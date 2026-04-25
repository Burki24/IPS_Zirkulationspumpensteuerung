<?php

declare(strict_types=1);

class ZirkulationByMotion extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        // Properties
        $this->RegisterPropertyString('MotionIDs', '[]'); // JSON Array
        $this->RegisterPropertyInteger('SwitchID', 0);
        $this->RegisterPropertyInteger('Runtime', 180);
        $this->RegisterPropertyInteger('RuntimeNight', 120);
        $this->RegisterPropertyInteger('LockTime', 600);
        $this->RegisterPropertyInteger('NightStart', 22);
        $this->RegisterPropertyInteger('NightEnd', 6);

        // Statusvariablen
        $this->RegisterVariableInteger('LastRun', 'Letzte Aktivierung', '~UnixTimestamp');
        $this->RegisterVariableInteger('RunCount', 'Anzahl Starts', '');
        $this->RegisterVariableBoolean('Active', 'Pumpe aktiv', '~Switch');

        // Timer
        $this->RegisterTimer('OffTimer', 0, 'ZBM_SwitchOff($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $motionIDs = json_decode($this->ReadPropertyString('MotionIDs'), true);

        if (is_array($motionIDs)) {
            foreach ($motionIDs as $id) {
                if (IPS_VariableExists($id)) {
                    $this->RegisterMessage($id, VM_UPDATE);
                }
            }
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === VM_UPDATE) {
            $this->HandleMotion($SenderID);
        }
    }

    private function HandleMotion(int $senderID): void
    {
        if (!GetValueBoolean($senderID)) {
            return;
        }

        $switchID = $this->ReadPropertyInteger('SwitchID');
        if (!IPS_VariableExists($switchID)) {
            return;
        }

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

        IPS_LogMessage('Zirkulation', 'Pumpe EIN für ' . $runtime . ' Sekunden');

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

        IPS_LogMessage('Zirkulation', 'Pumpe AUS');

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
