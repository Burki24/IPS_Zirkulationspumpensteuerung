<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/VariableProfileHelper.php';

class Zirkulationssteuerung extends IPSModuleStrict
{
    use VariableProfileHelper;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterProfileFloat('ZPS.Hours', 'Clock', '', ' h', 0, 0, 0, 2);

        // Geräte
        $this->RegisterPropertyInteger('MotionIDBath', 0);
        $this->RegisterPropertyInteger('MotionIDKitchen', 0);
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

        // Statistik
        $this->RegisterVariableInteger('DailyRuntime', 'Laufzeit heute', '');
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

        // Buffer
        if ($this->GetBuffer('LastDay') === '') {
            $this->SetBuffer('LastDay', date('Y-m-d'));
        }

        // Installationszeitpunkt für korrekte Tagesberechnung
        if ($this->GetBuffer('InstallTime') === '') {
            $this->SetBuffer('InstallTime', (string)time());
        }
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $bath = $this->ReadPropertyInteger('MotionIDBath');
        $kitchen = $this->ReadPropertyInteger('MotionIDKitchen');

        if ($bath > 0 && IPS_VariableExists($bath)) {
            $this->RegisterMessage($bath, VM_UPDATE);
        }

        if ($kitchen > 0 && IPS_VariableExists($kitchen)) {
            $this->RegisterMessage($kitchen, VM_UPDATE);
        }
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

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'ResetAction') {
            switch ($Value) {
                case 1: $this->ArmReset('daily'); break;
                case 2: if ($this->IsResetStillValid('daily')) $this->ResetDaily(); break;
                case 3: $this->ArmReset('total'); break;
                case 4: if ($this->IsResetStillValid('total')) $this->ResetTotal(); break;
                case 5: $this->ArmReset('all'); break;
                case 6: if ($this->IsResetStillValid('all')) $this->ResetAll(); break;
            }
            $this->SetValue('ResetAction', 0);
        }
    }

    public function SwitchOn(): void
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
    }

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
            $this->UpdateCostsPerRun($duration);
        }

        $this->UpdateRuntime();
        $this->UpdateEnergy();
        $this->UpdateSavings();
        $this->UpdateDaily();
        $this->UpdateCosts();

        $this->SetValue('Active', false);
        $this->SetBuffer('RunStart', '');
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
        $hours = $this->GetValue('TotalRuntime') / 3600;
        $power = $this->ReadPropertyFloat('PumpPower');

        $this->SetValue('EstimatedEnergy', round(($power / 1000) * $hours, 3));
    }

    private function UpdateSavings(): void
    {
        $install = (int)$this->GetBuffer('InstallTime');
        if ($install <= 0) return;

        $hours = (time() - $install) / 3600;
        $power = $this->ReadPropertyFloat('PumpPower');

        $full = ($power / 1000) * $hours;
        $saved = max(0, $full - $this->GetValue('EstimatedEnergy'));

        $this->SetValue('SavedEnergy', round($saved, 3));
    }

    private function UpdateDaily(): void
    {
        $start = (int)$this->GetBuffer('RunStart');
        if ($start <= 0) return;

        $duration = time() - $start;
        $runtime = $this->GetValue('DailyRuntime') + $duration;

        $this->SetValue('DailyRuntime', $runtime);

        $power = $this->ReadPropertyFloat('PumpPower');
        $energy = ($power / 1000) * ($runtime / 3600);

        $this->SetValue('DailyEnergy', round($energy, 3));

        // Einsparung heute berechnen
        $todayStart = strtotime(date('Y-m-d 00:00:00'));
        $install = (int)$this->GetBuffer('InstallTime');
        
        $startTime = max($todayStart, $install);
        $elapsed = max(0, time() - $startTime);
        
        $full = ($power / 1000) * ($elapsed / 3600);
        $saved = max(0, $full - $energy);
        
        $this->SetValue('DailySavings', round($saved, 3));
    }

    private function UpdateCosts(): void
    {
        $price = $this->ReadPropertyFloat('EnergyPrice');

        $this->SetValue('EnergyCost', round($this->GetValue('EstimatedEnergy') * $price, 2));
        $this->SetValue('DailyCost', round($this->GetValue('DailyEnergy') * $price, 2));
    }

    private function UpdateCostsPerRun(int $sec): void
    {
        $power = $this->ReadPropertyFloat('PumpPower');
        $price = $this->ReadPropertyFloat('EnergyPrice');

        $cost = (($power / 1000) * ($sec / 3600)) * $price;

        $this->SetValue('EnergyCostAccumulated',
            round($this->GetValue('EnergyCostAccumulated') + $cost, 2));

        $this->SetValue('DailyCostAccumulated',
            round($this->GetValue('DailyCostAccumulated') + $cost, 2));
    }
}
