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

        // Statistik
        $this->RegisterVariableInteger('DailyRuntime', 'Laufzeit heute (Sekunden)', '');
        $this->RegisterVariableFloat('DailyEnergy', 'Verbrauch heute', '~Electricity');
        $this->RegisterVariableFloat('DailySavings', 'Ersparnis heute', '~Electricity');

        $this->RegisterVariableInteger('TotalRuntime', 'Gesamtlaufzeit (Sekunden)', '');
        $this->RegisterVariableFloat('TotalRuntimeHours', 'Gesamtlaufzeit (Stunden)', 'ZPS.Hours');

        $this->RegisterVariableFloat('EstimatedEnergy', 'Verbrauch (kWh)', '~Electricity');
        $this->RegisterVariableFloat('SavedEnergy', 'Eingesparte Energie', '~Electricity');

        // Dynamische Kosten
        $this->RegisterVariableFloat('EnergyCost', 'Kosten gesamt (dynamisch)', '~Euro');
        $this->RegisterVariableFloat('DailyCost', 'Kosten heute (dynamisch)', '~Euro');

        // FIXE Kosten (pro Lauf)
        $this->RegisterVariableFloat('EnergyCostAccumulated', 'Kosten gesamt', '~Euro');
        $this->RegisterVariableFloat('DailyCostAccumulated', 'Kosten heute', '~Euro');

        // Timer
        $this->RegisterTimer('OffTimer', 0, 'ZPS_SwitchOff($_IPS["TARGET"]);');
        $this->RegisterTimer('ResetTimeout', 0, 'ZPS_ResetTimeout($_IPS["TARGET"]);');

        // Tageswechsel
        if ($this->GetBuffer('LastDay') === '') {
            $this->SetBuffer('LastDay', date('Y-m-d'));
        }

        // Installationszeit
        if ($this->GetBuffer('InstallTime') === '') {
            $installTime = time();
            $this->SetBuffer('InstallTime', (string)$installTime);

            $this->RegisterVariableInteger('InstallTime', 'Installationszeit', '~UnixTimestamp');
            $this->SetValue('InstallTime', $installTime);
        }

        // Preis
        if ($this->GetBuffer('LastPrice') === '') {
            $this->SetBuffer('LastPrice', '0');
        }
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

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

        $start = (int)$this->GetBuffer('RunStart');

        if ($start > 0) {
            $duration = time() - $start;
            $this->UpdateCostsPerRun($duration);
        }

        $this->CheckDailyReset();

        $this->UpdateRuntime();
        $this->UpdateEnergy();
        $this->UpdateSavings();
        $this->UpdateDaily();
        $this->UpdateCosts();

        $this->SetValue('Active', false);
        $this->SetBuffer('RunStart', '');
    }

    private function GetRuntime(): int
    {
        $runtime = $this->ReadPropertyInteger('Runtime');
        $hour = (int)date('G');

        if ($this->ReadPropertyBoolean('UseTimeControl')) {

            if ($this->IsInTimeRange($hour,
                $this->ReadPropertyInteger('TimeStart1'),
                $this->ReadPropertyInteger('TimeEnd1'))) {
                $runtime = $this->ReadPropertyInteger('Runtime1');
            }

            if ($this->IsInTimeRange($hour,
                $this->ReadPropertyInteger('TimeStart2'),
                $this->ReadPropertyInteger('TimeEnd2'))) {
                $runtime = $this->ReadPropertyInteger('Runtime2');
            }
        }

        $lastRun = (int)$this->GetBuffer('LastRun');
        $warmWindow = $this->ReadPropertyInteger('WarmWindow');

        if ($lastRun > 0 && (time() - $lastRun) < $warmWindow) {
            $reduction = $this->ReadPropertyInteger('WarmReduction');
            $runtime = (int)round($runtime * (1 - $reduction / 100));
        }

        return max(10, $runtime);
    }

    private function IsInTimeRange(int $hour, int $start, int $end): bool
    {
        if ($start > $end) {
            return ($hour >= $start || $hour < $end);
        }
        return ($hour >= $start && $hour < $end);
    }

    private function UpdateRuntime(): void
    {
        $start = (int)$this->GetBuffer('RunStart');
        if ($start <= 0) return;

        $duration = time() - $start;

        $total = $this->GetValue('TotalRuntime') + $duration;
        $this->SetValue('TotalRuntime', $total);
        $this->SetValue('TotalRuntimeHours', round($total / 3600, 2));
    }

    private function UpdateEnergy(): void
    {
        $seconds = $this->GetValue('TotalRuntime');
        $hours = $seconds / 3600;
        $power = $this->ReadPropertyFloat('PumpPower');

        $this->SetValue('EstimatedEnergy', round(($power / 1000) * $hours, 3));
    }

    private function UpdateSavings(): void
    {
        $installTime = (int)$this->GetBuffer('InstallTime');

        if ($installTime <= 0) {
            $installTime = time();
            $this->SetBuffer('InstallTime', (string)$installTime);
        }

        $runtimeHours = (time() - $installTime) / 3600;
        $power = $this->ReadPropertyFloat('PumpPower');

        $full = ($power / 1000) * $runtimeHours;
        $saved = $full - $this->GetValue('EstimatedEnergy');

        if ($saved < 0) $saved = 0;

        $this->SetValue('SavedEnergy', round($saved, 3));
    }

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

    private function UpdateDaily(): void
    {
        $start = (int)$this->GetBuffer('RunStart');
        if ($start <= 0) return;

        $duration = time() - $start;

        $runtime = $this->GetValue('DailyRuntime') + $duration;
        $this->SetValue('DailyRuntime', $runtime);

        $hours = $runtime / 3600;
        $power = $this->ReadPropertyFloat('PumpPower');

        $energy = ($power / 1000) * $hours;
        $this->SetValue('DailyEnergy', round($energy, 3));

        $todayStart = strtotime(date('Y-m-d 00:00:00'));
        $installTime = (int)$this->GetBuffer('InstallTime');

        if ($installTime <= 0) {
            $installTime = time();
            $this->SetBuffer('InstallTime', (string)$installTime);
        }

        $startTime = max($todayStart, $installTime);
        $elapsed = max(0, time() - $startTime);

        $full = ($power / 1000) * ($elapsed / 3600);
        $saved = $full - $energy;

        if ($saved < 0) $saved = 0;

        $this->SetValue('DailySavings', round($saved, 3));
    }

    private function UpdateCosts(): void
    {
        $price = $this->ReadPropertyFloat('EnergyPrice');

        $this->SetValue('EnergyCost', round($this->GetValue('EstimatedEnergy') * $price, 2));
        $this->SetValue('DailyCost', round($this->GetValue('DailyEnergy') * $price, 2));
    }

    private function UpdateCostsPerRun(int $durationSeconds): void
    {
        $power = $this->ReadPropertyFloat('PumpPower');
        $price = $this->ReadPropertyFloat('EnergyPrice');

        $hours = $durationSeconds / 3600;
        $energy = ($power / 1000) * $hours;
        $cost = $energy * $price;

        $this->SetValue('EnergyCostAccumulated',
            round($this->GetValue('EnergyCostAccumulated') + $cost, 2)
        );

        $this->SetValue('DailyCostAccumulated',
            round($this->GetValue('DailyCostAccumulated') + $cost, 2)
        );
    }
    // Reset der gespeicherten Werte mit Sicherheitsabfrage

    public function ArmReset(string $type): void
    {
        $this->SetBuffer('ResetArmed', $type);
        $this->SetBuffer('ResetArmedTime', (string)time());
    
        // Timer starten (10 Sekunden)
        $this->RegisterTimer('ResetTimeout', 10000, 'ZPS_ResetTimeout($_IPS["TARGET"]);');
    
        $this->ReloadForm();
    
        $this->SendDebug('Reset', "Reset vorbereitet: $type", 0);
    }

    public function ResetTimeout(): void
    {
        $this->SetBuffer('ResetArmed', '');
        $this->SetBuffer('ResetArmedTime', '');
    
        // Timer stoppen
        $this->SetTimerInterval('ResetTimeout', 0);
    
        $this->ReloadForm();
    
        $this->SendDebug('Reset', 'Reset automatisch zurückgesetzt (Timeout)', 0);
    }

    public function ResetDaily(): void
    {
        if (!$this->IsResetStillValid('daily')) {
            $this->SendDebug('Reset', 'Timeout abgelaufen', 0);
            return;
        }
        if ($this->GetBuffer('ResetArmed') !== 'daily') {
            $this->SendDebug('Reset', 'Daily Reset nicht bestätigt', 0);
            return;
        }
    
        $this->SetBuffer('ResetArmed', '');
    
        $this->SetValue('DailyRuntime', 0);
        $this->SetValue('DailyEnergy', 0.0);
        $this->SetValue('DailySavings', 0.0);
        $this->SetValue('DailyCost', 0.0);
        $this->SetValue('DailyCostAccumulated', 0.0);
    
        $this->SetBuffer('LastDay', date('Y-m-d'));
        $this->SetBuffer('ResetArmed', '');
        $this->SetBuffer('ResetArmedTime', '');
        $this->SetTimerInterval('ResetTimeout', 0);
        $this->ReloadForm();
    
        $this->SendDebug('Reset', 'Tageswerte zurückgesetzt', 0);
    }

    public function ResetTotal(): void
    {
        if (!$this->IsResetStillValid('total')) {
            $this->SendDebug('Reset', 'Timeout abgelaufen', 0);
            return;
        }
        if ($this->GetBuffer('ResetArmed') !== 'total') {
            $this->SendDebug('Reset', 'Total Reset nicht bestätigt', 0);
            return;
        }
    
        $this->SetBuffer('ResetArmed', '');
    
        $this->SetValue('TotalRuntime', 0);
        $this->SetValue('TotalRuntimeHours', 0.0);
        $this->SetValue('EstimatedEnergy', 0.0);
        $this->SetValue('SavedEnergy', 0.0);
        $this->SetValue('EnergyCost', 0.0);
        $this->SetValue('EnergyCostAccumulated', 0.0);
        $this->SetBuffer('ResetArmed', '');
        $this->SetBuffer('ResetArmedTime', '');
        $this->SetTimerInterval('ResetTimeout', 0);
        $this->ReloadForm();
    
        $this->SendDebug('Reset', 'Gesamtwerte zurückgesetzt', 0);
    }

    public function ResetAll(): void
    {
        if (!$this->IsResetStillValid('all')) {
            $this->SendDebug('Reset', 'Timeout abgelaufen', 0);
            return;
        }
        if ($this->GetBuffer('ResetArmed') !== 'all') {
            $this->SendDebug('Reset', 'ResetAll nicht bestätigt', 0);
            return;
        }
    
        $this->SetBuffer('ResetArmed', '');
    
        $this->ResetDaily();
        $this->ResetTotal();
    
        $this->SetValue('RunCount', 0);
        $this->SetValue('LastRun', 0);
    
        $this->SetBuffer('RunStart', '');
        $this->SetBuffer('KitchenEvents', '[]');
    
        $this->SendDebug('Reset', 'ALLE Werte zurückgesetzt', 0);
        $this->SetBuffer('ResetArmed', '');
        $this->SetBuffer('ResetArmedTime', '');
        $this->SetTimerInterval('ResetTimeout', 0);
        $this->ReloadForm();
    }

    public function IsResetStillValid(string $type): bool
    {
        if ($this->GetBuffer('ResetArmed') !== $type) {
            return false;
        }
    
        $time = (int)$this->GetBuffer('ResetArmedTime');
    
        return (time() - $time) <= 10;
    }
}
