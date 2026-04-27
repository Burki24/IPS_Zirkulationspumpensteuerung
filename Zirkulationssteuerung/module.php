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

        // Reset Profil (WebFront Steuerung)
        $this->RegisterProfileIntegerEx('ZPS.Reset', 'Warning', '', '', [
            [0, '---', '', -1],
            [1, '🟡 Tageswerte vorbereiten', '', 0xFFFF00],
            [2, '🔴 Tageswerte löschen', '', 0xFF0000],
            [3, '🟡 Gesamt vorbereiten', '', 0xFFFF00],
            [4, '🔴 Gesamt löschen', '', 0xFF0000],
            [5, '🟡 ALLES vorbereiten', '', 0xFFFF00],
            [6, '🔥 ALLES löschen', '', 0xFF0000]
        ]);

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

        // Reset Variable
        $this->RegisterVariableInteger('ResetAction', 'Reset Aktionen', 'ZPS.Reset');
        $this->EnableAction('ResetAction');

        // Timer
        $this->RegisterTimer('OffTimer', 0, 'ZPS_SwitchOff($_IPS["TARGET"]);');
        $this->RegisterTimer('ResetTimeout', 0, 'ZPS_ResetTimeout($_IPS["TARGET"]);');

        // Buffer Init
        if ($this->GetBuffer('LastDay') === '') {
            $this->SetBuffer('LastDay', date('Y-m-d'));
        }

        if ($this->GetBuffer('InstallTime') === '') {
            $this->SetBuffer('InstallTime', (string)time());
        }

        if ($this->GetBuffer('LastPrice') === '') {
            $this->SetBuffer('LastPrice', '0');
        }
    }

    public function RequestAction($Ident, $Value)
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

        $runtime = $this->GetRuntime();

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
        $id = $this->ReadPropertyInteger('SwitchID');
        if (!IPS_VariableExists($id)) return;

        RequestAction($id, false);
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

    // ================= RESET =================

    public function ArmReset(string $type): void
    {
        $this->SetBuffer('ResetArmed', $type);
        $this->SetBuffer('ResetArmedTime', (string)time());

        $this->SetTimerInterval('ResetTimeout', 10000);
    }

    public function ResetTimeout(): void
    {
        $this->SetBuffer('ResetArmed', '');
        $this->SetBuffer('ResetArmedTime', '');
        $this->SetTimerInterval('ResetTimeout', 0);
    }

    public function ResetDaily(): void
    {
        if (!$this->IsResetStillValid('daily')) return;

        $this->SetValue('DailyRuntime', 0);
        $this->SetValue('DailyEnergy', 0);
        $this->SetValue('DailySavings', 0);
        $this->SetValue('DailyCost', 0);
        $this->SetValue('DailyCostAccumulated', 0);

        $this->ResetTimeout();
    }

    public function ResetTotal(): void
    {
        if (!$this->IsResetStillValid('total')) return;

        $this->SetValue('TotalRuntime', 0);
        $this->SetValue('TotalRuntimeHours', 0);
        $this->SetValue('EstimatedEnergy', 0);
        $this->SetValue('SavedEnergy', 0);
        $this->SetValue('EnergyCost', 0);
        $this->SetValue('EnergyCostAccumulated', 0);

        $this->ResetTimeout();
    }

    public function ResetAll(): void
    {
        if (!$this->IsResetStillValid('all')) return;

        $this->ResetDaily();
        $this->ResetTotal();

        $this->SetValue('RunCount', 0);
        $this->SetValue('LastRun', 0);

        $this->ResetTimeout();
    }

    public function IsResetStillValid(string $type): bool
    {
        return $this->GetBuffer('ResetArmed') === $type &&
               (time() - (int)$this->GetBuffer('ResetArmedTime')) <= 10;
    }
}
