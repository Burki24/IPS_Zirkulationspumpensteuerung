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

        // Timer für Abschaltung
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

        $value = GetValue($SenderID);

        // Nur auf TRUE reagieren
        if (!(bool)$value) {
            return;
        }

        $bathID = $this->ReadPropertyInteger('MotionIDBath');
        $kitchenID = $this->ReadPropertyInteger('MotionIDKitchen');

        if ($SenderID === $bathID) {
            $this->TrySwitchOn();
            return;
        }

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

        // alte Events rauswerfen
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

        // Pumpe EIN
        RequestAction($switchID, true);

        // Abschalt-Timer
        $this->SetTimerInterval('OffTimer', $runtime * 1000);

        $now = time();
        $this->SetBuffer('LastRun', (string)$now);

        // ✅ KORREKTES Schreiben (IPSModuleStrict!)
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

        // Pumpe AUS
        RequestAction($switchID, false);

        // Timer stoppen
        $this->SetTimerInterval('OffTimer', 0);

        // Status setzen
        $this->SetValue('Active', false);
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
