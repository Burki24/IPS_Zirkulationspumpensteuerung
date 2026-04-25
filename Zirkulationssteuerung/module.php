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
        $this->EnableAction('LastRun');
        $this->RegisterVariableInteger('RunCount', 'Anzahl Starts', '');
        $this->EnableAction('RunCount');
        $this->RegisterVariableBoolean('Active', 'Pumpe aktiv', '~Switch');
        $this->EnableAction('Active');

        // Timer
        $this->RegisterTimer('OffTimer', 0, 'IPS_RequestAction($_IPS["TARGET"], "SwitchOff", 0);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $bathID = $this->ReadPropertyInteger('MotionIDBath');
        $kitchenID = $this->ReadPropertyInteger('MotionIDKitchen');

        $this->SendDebug('ApplyChanges', "BathID: $bathID | KitchenID: $kitchenID", 0);

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

        $value = GetValue($SenderID);
        $this->SendDebug('MessageSink', "ID: $SenderID | Wert: $value", 0);

        if (!(bool)$value) {
            return;
        }

        $bathID = $this->ReadPropertyInteger('MotionIDBath');
        $kitchenID = $this->ReadPropertyInteger('MotionIDKitchen');

        if ($SenderID === $bathID) {
            $this->SendDebug('Trigger', 'Bad erkannt', 0);
            $this->TrySwitchOn();
            return;
        }

        if ($SenderID === $kitchenID) {
            $this->SendDebug('Trigger', 'Küche erkannt', 0);
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
        $events[] = $now;

        $this->SetBuffer('KitchenEvents', json_encode($events));

        $count = count($events);
        $needed = $this->ReadPropertyInteger('TriggerCount');

        $this->SendDebug('Küche', "Events: $count / $needed", 0);

        if ($count >= $needed) {
            $this->SendDebug('Küche', 'Trigger ausgelöst', 0);
            $this->TrySwitchOn();
            $this->SetBuffer('KitchenEvents', json_encode([]));
        }
    }

    private function TrySwitchOn(): void
    {
        $lastRun = (int)$this->GetBuffer('LastRun');
        $lockTime = $this->ReadPropertyInteger('LockTime');

        if ($lastRun > 0 && (time() - $lastRun) < $lockTime) {
            $this->SendDebug('Lock', 'Sperrzeit aktiv', 0);
            return;
        }

        $this->SwitchOn();
    }

    public function SwitchOn(): void
    {
        $switchID = $this->ReadPropertyInteger('SwitchID');
    
        if (!IPS_VariableExists($switchID)) {
            $this->SendDebug('SwitchOn', 'SwitchID ungültig', 0);
            return;
        }
    
        $runtime = $this->GetRuntime();
        $this->SendDebug('SwitchOn', "Pumpe EIN für $runtime Sekunden", 0);
    
        RequestAction($switchID, true);
    
        // Timer starten
        $this->SetTimerInterval('OffTimer', $runtime * 1000);
    
        // Zeit setzen
        $now = time();
        $this->SetBuffer('LastRun', (string)$now);
    
        // IDs korrekt holen
        $lastRunID = $this->GetIDForIdent('LastRun');
        $runCountID = $this->GetIDForIdent('RunCount');
        $activeID = $this->GetIDForIdent('Active');
    
        // Debug (sehr hilfreich!)
        $this->SendDebug('IDs', "LastRun: $lastRunID | RunCount: $runCountID | Active: $activeID", 0);
    
        // Werte setzen
        if ($lastRunID !== false) {
            SetValue($lastRunID, $now);
        }
    
        if ($runCountID !== false) {
            $count = GetValue($runCountID);
            SetValue($runCountID, $count + 1);
        }
    
        if ($activeID !== false) {
            SetValue($activeID, true);
        }
    }

    public function SwitchOff(): void
    {
        $switchID = $this->ReadPropertyInteger('SwitchID');
    
        if (!IPS_VariableExists($switchID)) {
            return;
        }
    
        $this->SendDebug('SwitchOff', 'Pumpe AUS', 0);
    
        RequestAction($switchID, false);
    
        // Timer stoppen
        $this->SetTimerInterval('OffTimer', 0);
    
        // Active zurücksetzen
        $activeID = $this->GetIDForIdent('Active');
    
        if ($activeID !== false) {
            SetValue($activeID, false);
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void    {
        switch ($Ident) {
            case 'SwitchOff':
                $this->SwitchOff();
                break;
    
            case 'LastRun':
            case 'RunCount':
            case 'Active':
                // nur erlauben, nichts tun
                break;
        }
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
