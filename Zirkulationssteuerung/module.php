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

        // Timer
        $this->RegisterTimer('OffTimer', 0, 'ZPS_SwitchOff($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $bathID = $this->ReadPropertyInteger('MotionIDBath');
        $kitchenID = $this->ReadPropertyInteger('MotionIDKitchen');

        IPS_LogMessage('ZPS', "ApplyChanges - BathID: $bathID, KitchenID: $kitchenID");

        if ($bathID > 0 && IPS_VariableExists($bathID)) {
            IPS_LogMessage('ZPS', "Registriere Bad BWM: $bathID");
            $this->RegisterMessage($bathID, VM_UPDATE);
        }

        if ($kitchenID > 0 && IPS_VariableExists($kitchenID)) {
            IPS_LogMessage('ZPS', "Registriere Küchen BWM: $kitchenID");
            $this->RegisterMessage($kitchenID, VM_UPDATE);
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        $value = GetValue($SenderID);
    
        $this->SendDebug('MessageSink', "ID: $SenderID | Wert: $value", 0);
    
        if ($Message !== VM_UPDATE) {
            return;
        }
    
        // 👉 NUR auf Bewegung reagieren (TRUE)
        if (!$value) {
            return;
        }
    
        $bathID = $this->ReadPropertyInteger('MotionIDBath');
        $kitchenID = $this->ReadPropertyInteger('MotionIDKitchen');
    
        // 🛁 Bad → sofort
        if ($SenderID === $bathID) {
            $this->SendDebug('Trigger', 'Bad erkannt', 0);
            $this->TrySwitchOn();
            return;
        }
    
        // 🍽️ Küche → Muster
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

        IPS_LogMessage('ZPS', "Küche Events: $count / $needed");

        if ($count >= $needed) {
            IPS_LogMessage('ZPS', "Küche Trigger ausgelöst");
            $this->TrySwitchOn();
            $this->SetBuffer('KitchenEvents', json_encode([]));
        }
    }

    private function TrySwitchOn(): void
    {
        $lastRun = (int)$this->GetBuffer('LastRun');
        $lockTime = $this->ReadPropertyInteger('LockTime');

        if ($lastRun > 0 && (time() - $lastRun) < $lockTime) {
            IPS_LogMessage('ZPS', "Sperrzeit aktiv - kein Start");
            return;
        }

        $this->SwitchOn();
    }

    public function SwitchOn(): void
    {
        $switchID = $this->ReadPropertyInteger('SwitchID');

        if (!IPS_VariableExists($switchID)) {
            IPS_LogMessage('ZPS', "SwitchID ungültig");
            return;
        }

        $runtime = $this->GetRuntime();

        IPS_LogMessage('ZPS', "Pumpe EIN für $runtime Sekunden");

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

        IPS_LogMessage('ZPS', "Pumpe AUS");

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
