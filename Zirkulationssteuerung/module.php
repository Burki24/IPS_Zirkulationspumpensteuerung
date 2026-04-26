<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/VariableProfileHelper.php';

class Zirkulationssteuerung extends IPSModuleStrict
{
    use VariableProfileHelper;
    
    public function Create(): void
    {
        parent::Create();

        // Profile
        $this->RegisterProfileFloat('ZPS.Hours', 'Clock', '', ' h', 0, 0, 0, 2);

        // Geräte
        $this->RegisterPropertyInteger('MotionIDBath', 0);
        $this->RegisterPropertyInteger('MotionIDKitchen', 0);
        $this->RegisterPropertyInteger('SwitchID', 0);

        // Basislaufzeit
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

        // Berechnung
        $this->RegisterVariableInteger('DailyRuntime', 'Laufzeit heute (Sekunden)', '');
        $this->RegisterVariableFloat('DailyEnergy', 'Verbrauch heute', '~Electricity');
        $this->RegisterVariableFloat('DailySavings', 'Ersparnis heute', '~Electricity');
        $this->RegisterVariableInteger('TotalRuntime', 'Gesamtlaufzeit (Sekunden)', '');
        $this->RegisterVariableFloat('TotalRuntimeHours', 'Gesamtlaufzeit (Stunden)', 'ZPS.Hours');
        $this->RegisterVariableFloat('EstimatedEnergy', 'Verbrauch (kWh)', '~Electricity');
        $this->RegisterVariableFloat('SavedEnergy', 'Eingesparte Energie', '~Electricity');

        // Kosten
        $this->RegisterVariableFloat('EnergyCost', 'Kosten gesamt', '~Euro');
        $this->RegisterVariableFloat('DailyCost', 'Kosten heute', '~Euro');
        $this->RegisterVariableFloat('SavedCost', 'Ersparnis gesamt', '~Euro');
        $this->RegisterVariableFloat('DailySavedCost', 'Ersparnis heute', '~Euro');

        // Timer
        $this->RegisterTimer('OffTimer', 0, 'ZPS_SwitchOff($_IPS["TARGET"]);');

        // Tagesbuffer
        if ($this->GetBuffer('LastDay') === '') {
            $this->SetBuffer('LastDay', date('Y-m-d'));
        }

        // InstallTime
        if ($this->GetBuffer('InstallTime') === '') {
            $installTime = time();
            $this->SetBuffer('InstallTime', (string)$installTime);
            $this->RegisterVariableInteger('InstallTime', 'Installationszeit', '~UnixTimestamp');
            $this->SetValue('InstallTime', $installTime);
        }

        // Preis-Buffer
        if ($this->GetBuffer('LastPrice') === '') {
            $this->SetBuffer('LastPrice', '0');
        }
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Geräte
        $bathID = $this->ReadPropertyInteger('MotionIDBath');
        $kitchenID = $this->ReadPropertyInteger('MotionIDKitchen');

        if ($bathID > 0 && IPS_VariableExists($bathID)) {
            $this->RegisterMessage($bathID, VM_UPDATE);
        }

        if ($kitchenID > 0 && IPS_VariableExists($kitchenID)) {
            $this->RegisterMessage($kitchenID, VM_UPDATE);
        }

        // Preislogik
        $oldPrice = (float)$this->GetBuffer('LastPrice');
        $newPrice = $this->ReadPropertyFloat('EnergyPrice');

        if ($oldPrice == 0.0 && $newPrice > 0.0) {
            $this->UpdateCosts();
        }

        $this->SetBuffer('LastPrice', (string)$newPrice);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message !== VM_UPDATE) return;

        if (!(bool)GetValue($SenderID)) return;

        if ($SenderID === $this->ReadPropertyInteger('MotionIDBath')) {
            $this->TrySwitchOn();
            return;
        }

        if ($SenderID === $this->ReadPropertyInteger('MotionIDKitchen')) {
            $this->HandleKitchenMotion();
        }
    }

    private function HandleKitchenMotion(): void
    {
        $now = time();
        $events = json_decode($this->GetBuffer('KitchenEvents') ?: '[]', true) ?: [];

        $window = $this->ReadPropertyInteger('TriggerWindow');
        $events = array_filter($events, fn($t) => ($now - $t) <= $window);

        if (count($events) > 0 && ($now - end($events)) > 15) {
            $events = [];
        }

        $events[] = $now;
        $this->SetBuffer('KitchenEvents', json_encode($events));

        if (count($events) >= $this->ReadPropertyInteger('TriggerCount')) {
            $this->TrySwitchOn();
            $this->SetBuffer('KitchenEvents', json_encode([]));
        }
    }

    private function TrySwitchOn(): void
    {
        $lastRun = (int)$this->GetBuffer('LastRun');
        if ($lastRun > 0 && (time() - $lastRun) < $this->ReadPropertyInteger('LockTime')) {
            return;
        }

        $this->SwitchOn();
    }

    public function SwitchOn(): void
    {
        $switchID = $this->ReadPropertyInteger('SwitchID');
        if (!IPS_VariableExists($switchID)) return;

        $runtime = $this->GetRuntime();

        RequestAction($switchID, true);
        $this->SetTimerInterval('OffTimer', $runtime * 1000);

        $now = time();
        $this->SetBuffer('LastRun', (string)$now);
        $this->SetBuffer('RunStart', (string)$now);

        $this->SetValue('LastRun', $now);
        $this->SetValue('RunCount', $this->GetValue('RunCount') + 1);
        $this->SetValue('Active', true);
    }

    public function SwitchOff(): void
    {
        $switchID = $this->ReadPropertyInteger('SwitchID');
        if (!IPS_VariableExists($switchID)) return;

        RequestAction($switchID, false);
        $this->SetTimerInterval('OffTimer', 0);

        $this->CheckDailyReset();

        $this->UpdateRuntime();
        $this->UpdateEnergy();
        $this->UpdateSavings();
        $this->UpdateDaily();
        $this->UpdateCosts();

        $this->SetValue('Active', false);
        $this->SetBuffer('RunStart', '');
    }

    private function UpdateCosts(): void
    {
        $price = $this->ReadPropertyFloat('EnergyPrice');

        $this->SetValue('EnergyCost', round($this->GetValue('EstimatedEnergy') * $price, 2));
        $this->SetValue('SavedCost', round($this->GetValue('SavedEnergy') * $price, 2));
        $this->SetValue('DailyCost', round($this->GetValue('DailyEnergy') * $price, 2));
        $this->SetValue('DailySavedCost', round($this->GetValue('DailySavings') * $price, 2));
    }

    // ---- (Rest unverändert: Runtime, Energy, Daily etc.) ----
}
