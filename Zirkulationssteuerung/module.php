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

        // Statusvariablen (KEINE EnableAction!)
        $this->RegisterVariableInteger('LastRun', 'Letzte Aktivierung', '~UnixTimestamp');
        $this->RegisterVariableInteger('RunCount', 'Anzahl Starts', '');
        $this->RegisterVariableBoolean('Active', 'Pumpe aktiv', '~Switch');

        // Timer
        $this->RegisterTimer('OffTimer', 0, 'ZPS_SwitchOff($_IPS["TARGET"]);');
        $this->RegisterTimer('DeferredWrite', 0, 'ZPS_DoWrite($_IPS["TARGET"]);');
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

        RequestAction($switchID, true);

        $this->SetTimerInterval('OffTimer', $runtime * 1000);

        $now = time();
        $this->SetBuffer('LastRun', (string)$now);

        // Werte vorbereiten (NICHT direkt schreiben!)
        $this->SetBuffer('PendingLastRun', (string)$now);
        $this->SetBuffer('PendingIncrement', '1');
        $this->SetBuffer('PendingActive', '1');

        // Entkoppelt schreiben
        $this->SetTimerInterval('DeferredWrite', 1);
    }

    public function SwitchOff(): void
    {
        $switchID = $this->ReadPropertyInteger('SwitchID');

        if (!IPS_VariableExists($switchID)) {
            return;
        }

        RequestAction($switchID, false);

        $this->SetTimerInterval('OffTimer', 0);

        $this->SetBuffer('PendingActive', '0');

        // Entkoppelt schreiben
        $this->SetTimerInterval('DeferredWrite', 1);
    }

    public function DoWrite(): void
    {
        // Timer stoppen
        $this->SetTimerInterval('DeferredWrite', 0);

        $lastRun = (int)$this->GetBuffer('PendingLastRun');
        $increment = $this->GetBuffer('PendingIncrement') === '1';
        $activeBuffer = $this->GetBuffer('PendingActive');

        $lastRunID = $this->GetIDForIdent('LastRun');
        $runCountID = $this->GetIDForIdent('RunCount');
        $activeID = $this->GetIDForIdent('Active');

        if ($lastRunID > 0 && $lastRun > 0) {
            SetValue($lastRunID, $lastRun);
        }

        if ($runCountID > 0 && $increment) {
            SetValue($runCountID, GetValue($runCountID) + 1);
        }

        if ($activeID > 0 && $activeBuffer !== '') {
            SetValue($activeID, $activeBuffer === '1');
        }

        // Buffer löschen
        $this->SetBuffer('PendingLastRun', '');
        $this->SetBuffer('PendingIncrement', '');
        $this->SetBuffer('PendingActive', '');
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
