<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/VariableProfileHelper.php';

class Zirkulationssteuerung extends IPSModuleStrict
{
    use VariableProfileHelper;
    
    /**
     * Create
     *
     * Initialisiert das Modul und registriert alle Eigenschaften,
     * Variablen, Profile, Timer und internen Buffer.
     *
     * Diese Methode wird einmalig beim Anlegen der Instanz ausgeführt.
     *
     * @return void
     *
     * @see \IPSModule::Create()
     * @see \IPSModule::RegisterPropertyInteger()
     * @see \IPSModule::RegisterVariableInteger()
     * @see \IPSModule::RegisterTimer()
     */
    public function Create(): void
    {
        parent::Create();

        $this->RegisterProfileFloat('ZPS.Hours', 'Clock', '', ' h', 0, 0, 0, 2);
        $this->RegisterProfileFloat('ZPS.Minutes', 'Clock', '', ' min', 0, 0, 0, 2);
        $this->RegisterProfileIntegerEx('ZPS.StartReason', 'Information', '', '', [
            [1, 'Direkt', '', 0x00FF00],
            [2, 'Impulsbasiert', '', 0x0000FF],
            [3, 'Manuell', '', 0xAAAAAA]
        ]);

        // Geräte
        $this->RegisterPropertyInteger('MotionIDDirect', 0);
        $this->RegisterPropertyInteger('MotionIDImpulse', 0);
        $this->RegisterPropertyInteger('SwitchID', 0);

        // Laufzeit
        $this->RegisterPropertyInteger('Runtime', 180);

        // Zeitsteuerung
        $this->RegisterPropertyBoolean('UseTimeControl', false);
        $this->RegisterPropertyInteger('TimeStart1', 6);
        $this->RegisterPropertyInteger('TimeEnd1', 9);
        $this->RegisterPropertyInteger('Runtime1', 180);
        $this->RegisterPropertyInteger('TimeStart2', 18);
        $this->RegisterPropertyInteger('TimeEnd2', 23);
        $this->RegisterPropertyInteger('Runtime2', 180);

        // Warmwasser
        $this->RegisterPropertyInteger('WarmWindow', 600);
        $this->RegisterPropertyInteger('WarmReduction', 30);

        // Küche
        $this->RegisterPropertyInteger('TriggerCount', 3);
        $this->RegisterPropertyInteger('TriggerWindow', 60);

        // Sperrzeit
        $this->RegisterPropertyInteger('LockTime', 600);

        // Verbrauch
        $this->RegisterPropertyFloat('PumpPower', 30.0);
        $this->RegisterPropertyFloat('EnergyPrice', 0.30);

        // Status
        $this->RegisterVariableInteger('LastRun', 'Letzte Aktivierung', '~UnixTimestamp');
        $this->RegisterVariableInteger('RunCount', 'Anzahl Starts', '');
        $this->RegisterVariableBoolean('Active', 'Pumpe aktiv', '~Switch');
        $this->RegisterVariableInteger('StartReason', 'Startgrund', 'ZPS.StartReason');

        // Statistik
        $this->RegisterVariableInteger('DailyRuntime', 'Laufzeit heute', 'ZPS.Minutes');
        $this->RegisterVariableFloat('DailyEnergy', 'Verbrauch heute', '~Electricity');
        $this->RegisterVariableFloat('DailySavings', 'Ersparnis heute', '~Electricity');

        $this->RegisterVariableInteger('TotalRuntime', 'Gesamtlaufzeit', '');
        $this->RegisterVariableFloat('TotalRuntimeHours', 'Gesamtlaufzeit (h)', 'ZPS.Hours');

        $this->RegisterVariableFloat('EstimatedEnergy', 'Verbrauch gesamt', '~Electricity');
        $this->RegisterVariableFloat('SavedEnergy', 'Eingespart gesamt', '~Electricity');

        // Kosten
        $this->RegisterVariableFloat('EnergyCost', 'Kosten gesamt (dyn)', '~Euro');
        $this->RegisterVariableFloat('DailyCost', 'Kosten heute (dyn)', '~Euro');

        $this->RegisterVariableFloat('EnergyCostAccumulated', 'Kosten gesamt', '~Euro');
        $this->RegisterVariableFloat('DailyCostAccumulated', 'Kosten heute', '~Euro');

        // Timer
        $this->RegisterTimer('OffTimer', 0, 'ZPS_SwitchOff($_IPS["TARGET"]);');
        $this->RegisterTimer('DailyResetTimer', 0, 'ZPS_DailyReset($_IPS["TARGET"]);');

        // Buffer
        if ($this->GetBuffer('LastDay') === '') {
            $this->SetBuffer('LastDay', date('Y-m-d'));
        }

        if ($this->GetBuffer('InstallTime') === '') {
            $this->SetBuffer('InstallTime', (string)time());
        }
    }

    /**
     * ApplyChanges
     *
     * Übernimmt geänderte Einstellungen und registriert
     * Ereignisse (z.B. Bewegungsmelder) neu.
     *
     * Wird automatisch nach Änderungen in der Konfiguration ausgeführt.
     *
     * @return void
     *
     * @see \IPSModule::ApplyChanges()
     * @see \IPSModule::RegisterMessage()
     */
    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $direct = $this->ReadPropertyInteger('MotionIDDirect');
        $impulse = $this->ReadPropertyInteger('MotionIDImpulse');

        if ($direct > 0 && IPS_VariableExists($direct)) {
            $this->RegisterMessage($direct, VM_UPDATE);
        }

        if ($impulse > 0 && IPS_VariableExists($impulse)) {
            $this->RegisterMessage($impulse, VM_UPDATE);
        }

        $this->SetDailyResetTimer();
    }
    
    /**
     * MessageSink
     *
     * Reagiert auf Änderungen von verknüpften Variablen
     * (z.B. Bewegungsmelder).
     *
     * Startet abhängig vom Sender die Pumpenlogik.
     *
     * @param int   $TimeStamp Zeitpunkt der Änderung
     * @param int   $SenderID  ID der auslösenden Variable
     * @param int   $Message   Nachrichtentyp (z.B. VM_UPDATE)
     * @param array $Data      Zusatzdaten zur Nachricht
     *
     * @return void
     *
     * @see VM_UPDATE
     * @see \IPSModule::RegisterMessage()
     */
    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message !== VM_UPDATE) return;
        if (!(bool)GetValue($SenderID)) return;

        if ($SenderID === $this->ReadPropertyInteger('MotionIDDirect')) {
            $this->TrySwitchOn(1);
            return;
        }

        if ($SenderID === $this->ReadPropertyInteger('MotionIDImpulse')) {
            $this->HandleImpulseMotion();
        }
    }

    /**
     * HandleImpulseMotion
     *
     * Verarbeitet Bewegungen in der Küche und zählt Ereignisse
     * innerhalb eines Zeitfensters.
     *
     * Startet die Pumpe, wenn die definierte Anzahl an
     * Bewegungen erreicht wurde.
     *
     * @return void
     */
    private function HandleImpulseMotion(): void
    {
        $now = time();
        $events = json_decode($this->GetBuffer('ImpulseEvents') ?: '[]', true) ?: [];

        $window = $this->ReadPropertyInteger('TriggerWindow');
        $events = array_values(array_filter($events, fn($t) => ($now - $t) <= $window));

        // Reset wenn zu große Pause
        if (!empty($events) && ($now - end($events)) > 15) {
            $events = [];
        }

        $events[] = $now;
        $this->SetBuffer('ImpulseEvents', json_encode($events));

        if (count($events) >= $this->ReadPropertyInteger('TriggerCount')) {
            $this->TrySwitchOn(2);
            $this->SetBuffer('ImpulseEvents', json_encode([]));
        }
    }

    /**
     * TrySwitchOn
     *
     * Prüft die Sperrzeit seit der letzten Aktivierung
     * und startet die Pumpe nur, wenn diese abgelaufen ist.
     *
     * @return void
     */
    private function TrySwitchOn(int $reason = 1): void
    {
        if ($this->IsLocked()) return;
        $this->SwitchOn($reason);
    }

    /**
     * SwitchOn
     *
     * Schaltet die Pumpe ein und startet den Abschalt-Timer.
     *
     * Speichert Startzeit und erhöht den Start-Zähler.
     *
     * @return void
     *
     * @see RequestAction()
     * @see \IPSModule::SetTimerInterval()
     */
    public function SwitchOn(int $reason = 3): void
    {
        $id = $this->ReadPropertyInteger('SwitchID');
        if (!IPS_VariableExists($id)) return;

        $runtime = $this->ReadPropertyInteger('Runtime');

        RequestAction($id, true);
        $this->SetTimerInterval('OffTimer', $runtime * 1000);

        $now = time();
        $this->SetBuffer('RunStart', (string)$now);
        $this->SetBuffer('LastRun', (string)$now);

        $this->SetValue('LastRun', $now);
        $this->SetValue('RunCount', $this->GetValue('RunCount') + 1);
        $this->SetValue('Active', true);
        $this->SetValue('StartReason', $reason);
    }

    /**
     * SwitchOff
     *
     * Schaltet die Pumpe aus und berechnet alle relevanten Werte:
     * Laufzeit, Energie, Kosten und Einsparung.
     *
     * Wird automatisch durch den Timer ausgelöst.
     *
     * @return void
     *
     * @see UpdateRuntime()
     * @see UpdateEnergy()
     * @see UpdateCosts()
     */
    public function SwitchOff(): void
    {
        $this->CheckDailyReset();

        $id = $this->ReadPropertyInteger('SwitchID');
        if (!IPS_VariableExists($id)) return;

        RequestAction($id, false);
        $this->SetTimerInterval('OffTimer', 0);

        $start = (int)$this->GetBuffer('RunStart');

        if ($start > 0) {
            $duration = time() - $start;
            $power = $this->ReadPropertyFloat('PumpPower');

            $this->ProcessRun($duration, $power);
        }

        $this->SetValue('Active', false);
        $this->SetBuffer('RunStart', '');
    }

    /**
     * DailyReset
     *
     * Wird täglich durch den Timer ausgelöst und setzt
     * die Tageswerte zurück.
     *
     * Anschließend wird der Timer für den nächsten Tag neu gesetzt.
     *
     * @return void
     *
     * @see CheckDailyReset()
     */
    public function DailyReset(): void
    {
        $this->CheckDailyReset();
        $this->SetDailyResetTimer();
    }

    /**
     * CheckDailyReset
     *
     * Prüft, ob ein Tageswechsel stattgefunden hat und
     * setzt in diesem Fall die Tageswerte zurück.
     *
     * Wird zusätzlich als Sicherheitsmechanismus bei
     * jeder Pumpenabschaltung ausgeführt.
     *
     * @return void
     */
    private function CheckDailyReset(): void
    {
        $today = date('Y-m-d');

        if ($this->GetBuffer('LastDay') !== $today) {
            $this->SetValue('DailyRuntime', 0);
            $this->SetValue('DailyEnergy', 0.0);
            $this->SetValue('DailySavings', 0.0);
            $this->SetValue('DailyCostAccumulated', 0.0);

            $this->SetBuffer('LastDay', $today);
        }
    }

    /**
     * UpdateRuntime
     *
     * Berechnet die Laufzeit des aktuellen Pumpenlaufs
     * und addiert sie zur Gesamtlaufzeit.
     *
     * @return void
     */
    private function UpdateRuntime(): void
    {
        $start = (int)$this->GetBuffer('RunStart');
        if ($start <= 0) return;

        $duration = time() - $start;
        $total = $this->GetValue('TotalRuntime') + $duration;

        $this->SetValue('TotalRuntime', $total);
        $this->SetValue('TotalRuntimeHours', round($total / 3600, 2));
    }

    /**
     * UpdateEnergy
     *
     * Berechnet den gesamten Energieverbrauch auf Basis
     * der Laufzeit und der Pumpenleistung.
     *
     * @return void
     */
    private function UpdateEnergy(float $power): void
    {
        $hours = $this->GetValue('TotalRuntime') / 3600;
        $this->SetValue('EstimatedEnergy', round(($power / 1000) * $hours, 3));
    }

    /**
     * UpdateSavings
     *
     * Berechnet die eingesparte Energie im Vergleich
     * zu einem Dauerbetrieb seit Installation.
     *
     * @return void
     */
    private function UpdateSavings(float $power): void
    {
        $install = (int)$this->GetBuffer('InstallTime');
        if ($install <= 0) return;

        $hours = (time() - $install) / 3600;
        $full = ($power / 1000) * $hours;
        $saved = max(0, $full - $this->GetValue('EstimatedEnergy'));

        $this->SetValue('SavedEnergy', round($saved, 3));
    }

    /**
     * UpdateDaily
     *
     * Aktualisiert die Tageswerte für Laufzeit, Energieverbrauch
     * und Einsparung.
     *
     * Berücksichtigt dabei auch den Installationszeitpunkt,
     * falls der erste Tag kein voller Tag ist.
     *
     * @return void
     */
    private function UpdateDaily(float $power): void
    {
        $start = (int)$this->GetBuffer('RunStart');
        if ($start <= 0) return;

        $duration = time() - $start;
        $runtime = $this->GetValue('DailyRuntime') + $duration;
        $minutes = round($runtime / 60, 2);
        $this->SetValue('DailyRuntime', $minutes);

        $energy = ($power / 1000) * ($runtime / 3600);
        $this->SetValue('DailyEnergy', round($energy, 3));

        $todayStart = strtotime(date('Y-m-d 00:00:00'));
        $install = (int)$this->GetBuffer('InstallTime');

        $startTime = max($todayStart, $install);
        $elapsed = max(0, time() - $startTime);

        $full = ($power / 1000) * ($elapsed / 3600);
        $saved = max(0, $full - $energy);

        $this->SetValue('DailySavings', round($saved, 3));
    }

    /**
     * UpdateCosts
     *
     * Berechnet die aktuellen Stromkosten basierend auf
     * Energieverbrauch und Strompreis.
     *
     * @return void
     */
    private function UpdateCosts(): void
    {
        $price = $this->ReadPropertyFloat('EnergyPrice');

        $this->SetValue('EnergyCost', round($this->GetValue('EstimatedEnergy') * $price, 2));
        $this->SetValue('DailyCost', round($this->GetValue('DailyEnergy') * $price, 2));
    }

    /**
     * UpdateCostsPerRun
     *
     * Berechnet die Kosten eines einzelnen Pumpenlaufs
     * und addiert diese zu den kumulierten Werten.
     *
     * @param int $sec Laufzeit des Pumpenlaufs in Sekunden
     *
     * @return void
     */
    private function UpdateCostsPerRun(int $sec, float $power): void
    {
        $price = $this->ReadPropertyFloat('EnergyPrice');
        $cost = (($power / 1000) * ($sec / 3600)) * $price;

        $this->SetValue('EnergyCostAccumulated',
            round($this->GetValue('EnergyCostAccumulated') + $cost, 2));

        $this->SetValue('DailyCostAccumulated',
            round($this->GetValue('DailyCostAccumulated') + $cost, 2));
    }

    /**
     * SetDailyResetTimer
     *
     * Setzt den Timer so, dass er exakt um Mitternacht
     * ausgelöst wird.
     *
     * @return void
     */
    private function SetDailyResetTimer(): void
    {
        $now = time();
        $midnight = strtotime(date('Y-m-d 00:00:00') . ' +1 day');
        $ms = ($midnight - $now) * 1000;

        $this->SetTimerInterval('DailyResetTimer', $ms);
    }

    private function ProcessRun(int $duration, float $power): void
    {
        $this->UpdateCostsPerRun($duration, $power);
        $this->UpdateRuntime();
        $this->UpdateEnergy($power);
        $this->UpdateSavings($power);
        $this->UpdateDaily($power);
        $this->UpdateCosts();
    }

    private function IsLocked(): bool
    {
        $lastRun = (int)$this->GetBuffer('LastRun');
        if ($lastRun <= 0) return false;

        return (time() - $lastRun) < $this->ReadPropertyInteger('LockTime');
    }
}
