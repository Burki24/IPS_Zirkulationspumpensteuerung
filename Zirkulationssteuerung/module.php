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

        // Warmwasser-Optimierung
        $this->RegisterPropertyInteger('WarmWindow', 600);
        $this->RegisterPropertyInteger('WarmReduction', 30);

        // Küche Logik
        $this->RegisterPropertyInteger('TriggerCount', 3);
        $this->RegisterPropertyInteger('TriggerWindow', 60);

        // Sperrzeit
        $this->RegisterPropertyInteger('LockTime', 600);

        // Verbrauch
        $this->RegisterPropertyFloat('PumpPower', 30.0); // Watt

        // Statusvariablen
        $this->RegisterVariableInteger('LastRun', 'Letzte Aktivierung', '~UnixTimestamp');
        $this->RegisterVariableInteger('RunCount', 'Anzahl Starts', '');
        $this->RegisterVariableBoolean('Active', 'Pumpe aktiv', '~Switch');

        $this->RegisterVariableInteger('TotalRuntime', 'Gesamtlaufzeit (Sekunden)', '');
        $this->RegisterVariableFloat('TotalRuntimeHours', 'Gesamtlaufzeit (Stunden)', 'ZPS.Hours');
        $this->RegisterVariableFloat('EstimatedEnergy', 'Verbrauch (kWh)', '~Electricity');
        $this->RegisterVariableFloat('SavedEnergy', 'Eingesparte Energie', '~Electricity');

        // Timer
        $this->RegisterTimer('OffTimer', 0, 'ZPS_SwitchOff($_IPS["TARGET"]);');
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
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message !== VM_UPDATE) {
            return;
        }

        if (!(bool)GetValue($SenderID)) {
            return;
        }

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

        $events = json_decode($this->GetBuffer('KitchenEvents') ?: '[]', true);
        if (!is_array($events)) {
            $events = [];
        }

        $window = $this->ReadPropertyInteger('TriggerWindow');

        $events = array_filter($events, fn($t) => ($now - $t) <= $window);

        if (count($events) > 0) {
            $delta = $now - end($events);
            if ($delta > 15) {
                $events = [];
            }
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
        $lockTime = $this->ReadPropertyInteger('LockTime');

        if ($lastRun > 0 && (time() - $lastRun) < $lockTime) {
            return;
        }

        $this->SwitchOn();
    }

    public function SwitchOn(): void
    {
        $switchID = $this->ReadPropertyInteger('SwitchID');
        if (!IPS_VariableExists($switchID)) {
            return;
        }

        $runtime = $this->GetRuntime();

        RequestAction($switchID, true);
        $this->SetTimerInterval('OffTimer', $runtime * 1000);

        $now = time();
        $this->SetBuffer('LastRun', (string)$now);
        $this->SetBuffer('RunStart', (string)$now);

        // Status setzen (Strict korrekt)
        $this->SetValue('LastRun', $now);
        $this->SetValue('RunCount', $this->GetValue('RunCount') + 1);
        $this->SetValue('Active', true);
    }

    public function SwitchOff(): void
    {
        $switchID = $this->ReadPropertyInteger('SwitchID');
        if (!IPS_VariableExists($switchID)) {
            return;
        }
    
        // Pumpe ausschalten
        RequestAction($switchID, false);
    
        // Timer stoppen
        $this->SetTimerInterval('OffTimer', 0);
    
        // 🔧 Ausgelagerte Logik
        $this->UpdateRuntime();
        $this->UpdateEnergy();
        $this->UpdateSavings();
    
        // Status setzen
        $this->SetValue('Active', false);
    }

    private function GetRuntime(): int
    {
        $runtime = $this->ReadPropertyInteger('Runtime');
        $hour = (int)date('G');

        if ($this->ReadPropertyBoolean('UseTimeControl')) {

            if ($this->IsInTimeRange(
                $hour,
                $this->ReadPropertyInteger('TimeStart1'),
                $this->ReadPropertyInteger('TimeEnd1')
            )) {
                $runtime = $this->ReadPropertyInteger('Runtime1');
            }

            if ($this->IsInTimeRange(
                $hour,
                $this->ReadPropertyInteger('TimeStart2'),
                $this->ReadPropertyInteger('TimeEnd2')
            )) {
                $runtime = $this->ReadPropertyInteger('Runtime2');
            }
        }

        // Warmwasser-Erkennung
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

    private function UpdateSavings(): void
    {
        $installTime = (int)$this->GetBuffer('InstallTime');
    
        if ($installTime <= 0) {
            return;
        }
    
        $runtimeSeconds = time() - $installTime;
        $runtimeHoursTotal = $runtimeSeconds / 3600;
    
        $power = $this->ReadPropertyFloat('PumpPower');
    
        $fullEnergy = ($power / 1000) * $runtimeHoursTotal;
        $realEnergy = $this->GetValue('EstimatedEnergy');
    
        $saved = $fullEnergy - $realEnergy;
    
        if ($saved < 0) {
            $saved = 0;
        }
    
        $this->SetValue('SavedEnergy', round($saved, 3));
    }

    private function UpdateEnergy(): void
    {
        $hours = $this->GetValue('TotalRuntimeHours');
        $power = $this->ReadPropertyFloat('PumpPower');
    
        $energy = ($power / 1000) * $hours;
    
        $this->SetValue('EstimatedEnergy', round($energy, 3));
    }

    private function UpdateRuntime(): void
    {
        $start = (int)$this->GetBuffer('RunStart');
    
        if ($start <= 0) {
            return;
        }
    
        $duration = time() - $start;
    
        $total = $this->GetValue('TotalRuntime') + $duration;
        $this->SetValue('TotalRuntime', $total);
    
        $hours = $total / 3600;
        $this->SetValue('TotalRuntimeHours', round($hours, 2));
    }
}
