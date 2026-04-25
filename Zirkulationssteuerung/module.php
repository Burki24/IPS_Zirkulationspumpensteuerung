<?php

declare(strict_types=1);

class Zirkulationssteuerung extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        // Properties
        $this->RegisterPropertyInteger('MotionIDBath', 0);
        $this->RegisterPropertyInteger('MotionIDKitchen', 0);
        $this->RegisterPropertyInteger('SwitchID', 0);

        $this->RegisterPropertyInteger('Runtime', 180);
        $this->RegisterPropertyInteger('RuntimeNight', 120);
        $this->RegisterPropertyInteger('LockTime', 600);

        $this->RegisterPropertyInteger('TriggerCount', 3);
        $this->RegisterPropertyInteger('TriggerWindow', 60);

        $this->RegisterPropertyInteger('NightStart', 22);
        $this->RegisterPropertyInteger('NightEnd', 6);

        // Statusvariablen
        $this->RegisterVariableInteger('LastRun', 'Letzte Aktivierung', '~UnixTimestamp');
        $this->RegisterVariableInteger('RunCount', 'Anzahl Starts', '');
        $this->RegisterVariableBoolean('Active', 'Pumpe aktiv', '~Switch');

        // Timer (Prefix beachten!)
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

        if (!GetValueBoolean($SenderID)) {
            return;
        }

        $bathID = $this->ReadPropertyInteger('MotionIDBath');
        $kitchenID = $this->ReadPropertyInteger('MotionIDKitchen');

        // 🛁 Bad → sofort
        if ($SenderID === $bathID) {
            $this->TrySwitchOn();
            return;
        }

        // 🍽️ Küche → Mustererkennung
        if ($SenderID === $kitchenID) {
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

        // alte Events entfernen
        $events = array_filter($events, fn($t) => ($now - $t) <= $window);

        // neues Event hinzufügen
        $events[] = $now;

        $this->SetBuffer('KitchenEvents', json_encode($events));

        $count = count($events);
        $needed = $this->ReadPropertyInteger('TriggerCount');

        if ($count >= $needed) {
            IPS_LogMessage('ZPS', "Küche Trigger erkannt ($count/$needed)");

            $this->TrySwitchOn();

            // Reset
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

        IPS_LogMessage('ZPS', 'Pumpe EIN für ' . $runtime . ' Sekunden');

        RequestAction($switchID, true);

        $this->SetTimerInterval('OffTimer', $runtime * 1000);

        $this->SetBuffer('LastRun', (string)time());
        SetValue($this->GetIDForIdent('LastRun'), time());

        $count = GetValue($this->GetIDForIdent('RunCount'));
        SetValue($this->GetIDForIdent('RunCount'), $count + 1);

        SetValue($this->GetIDForIdent('Active'), true);
    }

    public function SwitchOff(): void
    {
        $switchID = $this->ReadPropertyInteger('SwitchID');

        if (!IPS_VariableExists($switchID)) {
            return;
        }

        IPS_LogMessage('ZPS', 'Pumpe AUS');

        RequestAction($switchID, false);

        $this->SetTimerInterval('OffTimer', 0);

        SetValue($this->GetIDForIdent('Active'), false);
    }

    private function GetRuntime(): int
    {
        $hour = (int)date('G');

        $nightStart = $this->ReadPropertyInteger('NightStart');
        $nightEnd = $this->ReadPropertyInteger('NightEnd');

        if ($nightStart > $nightEnd) {
            if ($hour >= $nightStart || $hour < $nightEnd) {
                return $this->ReadPropertyInteger('RuntimeNight');
            }
        } else {
            if ($hour >= $nightStart && $hour < $nightEnd) {
                return $this->ReadPropertyInteger('RuntimeNight');
            }
        }

        return $this->ReadPropertyInteger('Runtime');
    }
}
