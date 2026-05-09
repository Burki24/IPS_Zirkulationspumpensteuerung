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
            [1, 'Direct', '', 0x00FF00],
            [2, 'Impulse-based', '', 0x0000FF],
            [3, 'Manual', '', 0xAAAAAA]
        ]);
        $this->RegisterProfileIntegerEx('ZPS.ImpulseMode', 'Information', '', '', [
            [1, 'Combined (all sensors)', '', 0x00AAFF],
            [2, 'Same sensor only', '', 0x66CC66],
            [3, 'Different sensors only', '', 0xFFAA00]
        ]);

        // Geräte
        $this->RegisterPropertyInteger('MotionIDDirect', 0);
        $this->RegisterPropertyInteger('MotionIDDirect2', 0);
        $this->RegisterPropertyInteger('MotionIDDirect3', 0);
        $this->RegisterPropertyInteger('MotionIDImpulse', 0);
        $this->RegisterPropertyInteger('MotionIDImpulse2', 0);
        $this->RegisterPropertyInteger('MotionIDImpulse3', 0);
        $this->RegisterPropertyInteger('ButtonID', 0);
        $this->RegisterPropertyInteger('ButtonID2', 0);
        $this->RegisterPropertyInteger('ButtonID3', 0);
        $this->RegisterPropertyInteger('SwitchID', 0);
        $this->RegisterPropertyBoolean('Enabled', true);


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
        $this->RegisterPropertyInteger('ImpulseMode', 1);

        // Sperrzeit
        $this->RegisterPropertyInteger('LockTime', 600);

        // Verbrauch
        $this->RegisterPropertyFloat('PumpPower', 30.0);
        $this->RegisterPropertyFloat('EnergyPrice', 0.30);

        // Persistente Attribute
        $this->RegisterAttributeInteger('InstallTime', 0);

        // Status
        $this->RegisterVariableInteger('LastRun', 'Last activation', '~UnixTimestamp');
        $this->RegisterVariableInteger('RunCount', 'Start count', '');
        $this->RegisterVariableBoolean('Active', 'Pump active', '~Switch');
        $this->RegisterVariableInteger('StartReason', 'Start reason', 'ZPS.StartReason');
        $this->RegisterVariableBoolean('ModuleActive', 'Automation active', '~Switch');

        // Statistik
        //$this->RegisterVariableFloat('DailyRuntime', 'Runtime today', 'ZPS.Minutes');
        $this->RegisterVariableFloat('DailyRuntimeMinutes', 'Runtime today (min)', 'ZPS.Minutes');
        $this->RegisterVariableFloat('DailyEnergy', 'Consumption today', '~Electricity');
        $this->RegisterVariableFloat('DailySavings', 'Savings today', '~Electricity');

        $this->RegisterVariableInteger('TotalRuntime', 'Total runtime', '');
        $this->RegisterVariableFloat('TotalRuntimeHours', 'Total runtime (h)', 'ZPS.Hours');

        $this->RegisterVariableFloat('EstimatedEnergy', 'Total consumption', '~Electricity');
        $this->RegisterVariableFloat('SavedEnergy', 'Total savings', '~Electricity');

        // Kosten
        $this->RegisterVariableFloat('EnergyCost', 'Total cost (dyn)', '~Euro');
        $this->RegisterVariableFloat('DailyCost', 'Cost today (dyn)', '~Euro');

        $this->RegisterVariableFloat('EnergyCostAccumulated', 'Total cost', '~Euro');
        $this->RegisterVariableFloat('DailyCostAccumulated', 'Cost today', '~Euro');

        // Timer
        $this->RegisterTimer('OffTimer', 0, 'ZPS_SwitchOff($_IPS["TARGET"]);');
        $this->RegisterTimer('DailyResetTimer', 0, 'ZPS_DailyReset($_IPS["TARGET"]);');

        // Buffer
        if ($this->GetBuffer('LastDay') === '') {
            $this->SetBuffer('LastDay', date('Y-m-d'));
        }
        if ($this->GetBuffer('DailyRuntime') === '') {
            $this->SetBuffer('DailyRuntime', '0');
        }

        $attributeInstallTime = $this->ReadAttributeInteger('InstallTime');
        $bufferInstallTime = (int)$this->GetBuffer('InstallTime');

        if ($attributeInstallTime <= 0) {
            if ($bufferInstallTime > 0) {
                $attributeInstallTime = $bufferInstallTime;
            } else {
                $attributeInstallTime = time();
            }
            $this->WriteAttributeInteger('InstallTime', $attributeInstallTime);
        }

        $this->SetBuffer('InstallTime', (string)$attributeInstallTime);
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

        $previousSensorIds = json_decode($this->GetBuffer('RegisteredSensorIDs') ?: '[]', true) ?: [];
        foreach ($previousSensorIds as $sensorId) {
            if (is_int($sensorId) && $sensorId > 0 && IPS_VariableExists($sensorId)) {
                $this->UnregisterMessage($sensorId, VM_UPDATE);
            }
        }

        $currentSensorIds = array_values(array_unique(array_merge(
            $this->GetDirectMotionIDs(),
            $this->GetImpulseMotionIDs(),
            $this->GetButtonIDs()
        )));
        foreach ($currentSensorIds as $sensorId) {
            if ($sensorId > 0 && IPS_VariableExists($sensorId)) {
                $this->RegisterMessage($sensorId, VM_UPDATE);
            }
        }

        $this->SetBuffer('RegisteredSensorIDs', json_encode($currentSensorIds));

        $this->SetDailyResetTimer();

        $this->SetValue(
            'ModuleActive',
            $this->ReadPropertyBoolean('Enabled')
        );
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

        if (in_array($SenderID, $this->GetButtonIDs(), true)) {
            $this->SwitchOn(3);
            return;
        }

        if (in_array($SenderID, $this->GetDirectMotionIDs(), true)) {
            $this->TrySwitchOn(1);
            return;
        }

        if (in_array($SenderID, $this->GetImpulseMotionIDs(), true)) {
            $this->HandleImpulseMotion($SenderID);
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
    private function HandleImpulseMotion(int $senderId): void
    {
        $now = time();
        $events = $this->NormalizeImpulseEvents(json_decode($this->GetBuffer('ImpulseEvents') ?: '[]', true) ?: []);

        $window = $this->ReadPropertyInteger('TriggerWindow');
        $events = array_values(array_filter($events, static fn(array $event) => ($now - $event['time']) <= $window));

        $events[] = ['id' => $senderId, 'time' => $now];
        $this->SetBuffer('ImpulseEvents', json_encode($events));

        if ($this->IsImpulseTriggerReached($events, $senderId)) {
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
        // Modul deaktiviert
        if (!$this->ReadPropertyBoolean('Enabled')) {
            return;
        }

        // Sperrzeit aktiv
        if ($this->IsLocked()) {
            return;
        }

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
        if ($this->GetValue('Active')) {
            return;
        }
        if (
            !$this->ReadPropertyBoolean('Enabled') &&
            $reason !== 3
        ) {
            return;
        }

        $id = $this->ReadPropertyInteger('SwitchID');
        if (!IPS_VariableExists($id)) return;

        $runtime = $this->DetermineRuntime();

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
        $this->SetTimerInterval('OffTimer', 0);

        if (IPS_VariableExists($id)) {
            RequestAction($id, false);
        }

        $start = (int)$this->GetBuffer('RunStart');

        if ($start > 0) {
            $duration = time() - $start;
            $power = $this->ReadPropertyFloat('PumpPower');

            $this->ProcessRun($duration, $power);
        }

        $this->SetValue('Active', false);
        $this->SetBuffer('RunStart', '');
    }

    public function Destroy(): void
    {
        foreach (array_values(array_unique(array_merge($this->GetDirectMotionIDs(), $this->GetImpulseMotionIDs()))) as $sensorId) {
            if ($sensorId > 0 && IPS_VariableExists($sensorId)) {
                $this->UnregisterMessage($sensorId, VM_UPDATE);
            }
        }

        $this->UnregisterProfile('ZPS.Hours');
        $this->UnregisterProfile('ZPS.Minutes');
        $this->UnregisterProfile('ZPS.StartReason');
        $this->UnregisterProfile('ZPS.ImpulseMode');

        parent::Destroy();
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
            $this->SetBuffer('DailyRuntime', '0');
            $this->SetValue('DailyRuntimeMinutes', 0.0);
            $this->SetValue('DailyEnergy', 0.0);
            $this->SetValue('DailySavings', 0.0);
            $this->SetValue('DailyCost', 0.0);
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
    private function UpdateRuntime(int $duration): void
    {
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
    private function UpdateSavingsPerRun(int $duration, float $power): void
    {
        // Verbrauch bei Dauerbetrieb
        $fullEnergy = ($power / 1000) * ($duration / 3600);

        // Tatsächlicher Verbrauch des Pumpenlaufs
        $usedEnergy = $fullEnergy;

        // Eingesparte Energie:
        // Dauerbetrieb MINUS tatsächlicher Betrieb
        // => bei einem einzelnen Lauf praktisch 0
        // Deshalb rechnen wir gegen die Sperrzeit

        $lockTime = max(1, $this->ReadPropertyInteger('LockTime'));

        $theoreticalContinuous =
            ($power / 1000) * ($lockTime / 3600);

        $saved = max(0, $theoreticalContinuous - $usedEnergy);

        $this->SetValue(
            'SavedEnergy',
            round(
                $this->GetValue('SavedEnergy') + $saved,
                3
            )
        );

        $this->SetValue(
            'DailySavings',
            round(
                $this->GetValue('DailySavings') + $saved,
                3
            )
        );
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
    private function UpdateDaily(int $duration, float $power): void
    {
        // Laufzeit intern in Sekunden speichern
        $runtimeSeconds = (int)$this->GetBuffer('DailyRuntime');

        $runtimeSeconds += $duration;

        $this->SetBuffer('DailyRuntime', (string)$runtimeSeconds);

        // Sichtbare Laufzeit in Minuten
        $this->SetValue(
            'DailyRuntimeMinutes',
            round($runtimeSeconds / 60, 2)
        );

        // Tagesverbrauch berechnen
        $energy = ($power / 1000) * ($runtimeSeconds / 3600);

        $this->SetValue(
            'DailyEnergy',
            round($energy, 3)
        );
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
        $this->UpdateSavingsPerRun($duration, $power);
        $this->UpdateRuntime($duration);
        $this->UpdateEnergy($power);
        $this->UpdateDaily($duration, $power);
        $this->UpdateCosts();
    }

    private function IsLocked(): bool
    {
        $lastRun = (int)$this->GetBuffer('LastRun');
        if ($lastRun <= 0) return false;

        return (time() - $lastRun) < $this->ReadPropertyInteger('LockTime');
    }

    private function DetermineRuntime(): int
    {
        $runtime = max(1, $this->ReadPropertyInteger('Runtime'));

        if ($this->ReadPropertyBoolean('UseTimeControl')) {
            $hour = (int)date('G');

            if ($this->IsInHourWindow($hour, $this->ReadPropertyInteger('TimeStart1'), $this->ReadPropertyInteger('TimeEnd1'))) {
                $runtime = max(1, $this->ReadPropertyInteger('Runtime1'));
            } elseif ($this->IsInHourWindow($hour, $this->ReadPropertyInteger('TimeStart2'), $this->ReadPropertyInteger('TimeEnd2'))) {
                $runtime = max(1, $this->ReadPropertyInteger('Runtime2'));
            }
        }

        $lastRun = (int)$this->GetBuffer('LastRun');
        $warmWindow = max(0, $this->ReadPropertyInteger('WarmWindow'));

        if ($lastRun > 0 && $warmWindow > 0 && (time() - $lastRun) <= $warmWindow) {
            $reduction = max(0, min(100, $this->ReadPropertyInteger('WarmReduction')));
            $runtime = max(1, (int)round($runtime * (1 - ($reduction / 100))));
        }

        return $runtime;
    }

    private function IsInHourWindow(int $hour, int $start, int $end): bool
    {
        $start = max(0, min(23, $start));
        $end = max(0, min(23, $end));

        if ($start <= $end) {
            return $hour >= $start && $hour <= $end;
        }

        return $hour >= $start || $hour <= $end;
    }

    private function GetDirectMotionIDs(): array
    {
        return array_values(array_filter([
            $this->ReadPropertyInteger('MotionIDDirect'),
            $this->ReadPropertyInteger('MotionIDDirect2'),
            $this->ReadPropertyInteger('MotionIDDirect3')
        ], static fn(int $id) => $id > 0));
    }

    private function GetImpulseMotionIDs(): array
    {
        return array_values(array_filter([
            $this->ReadPropertyInteger('MotionIDImpulse'),
            $this->ReadPropertyInteger('MotionIDImpulse2'),
            $this->ReadPropertyInteger('MotionIDImpulse3')
        ], static fn(int $id) => $id > 0));
    }

    private function IsImpulseTriggerReached(array $events, int $currentSenderId): bool
    {
        $triggerCount = max(1, $this->ReadPropertyInteger('TriggerCount'));
        $mode = $this->ReadPropertyInteger('ImpulseMode');

        if ($mode === 2) {
            $sameSensorCount = count(array_filter($events, static fn(array $event): bool => $event['id'] === $currentSenderId));
            return $sameSensorCount >= $triggerCount;
        }

        if ($mode === 3) {
            $uniqueSensors = array_unique(array_map(static fn(array $event): int => (int)$event['id'], $events));
            return count($uniqueSensors) >= $triggerCount;
        }

        return count($events) >= $triggerCount;
    }

    private function NormalizeImpulseEvents(array $events): array
    {
        $normalized = [];

        foreach ($events as $event) {
            if (is_int($event)) {
                $normalized[] = ['id' => 0, 'time' => $event];
                continue;
            }

            if (is_array($event) && array_key_exists('time', $event)) {
                $normalized[] = [
                    'id' => isset($event['id']) ? (int)$event['id'] : 0,
                    'time' => (int)$event['time']
                ];
            }
        }

        return $normalized;
    }

    private function GetButtonIDs(): array
    {
        return array_values(array_filter([
            $this->ReadPropertyInteger('ButtonID'),
            $this->ReadPropertyInteger('ButtonID2'),
            $this->ReadPropertyInteger('ButtonID3')
        ], static fn(int $id): bool => $id > 0));
    }
}
