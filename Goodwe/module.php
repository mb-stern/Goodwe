<?php

declare(strict_types=1);

class Goodwe extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->ConnectParent("{A5F663AB-C400-4FE5-B207-4D67CC030564}");
        $this->RegisterPropertyString("SelectedRegisters", "[]");
        $this->RegisterAttributeString("SavedRegisters", "[]");
        $this->RegisterTimer("Poller", 0, 'Goodwe_RequestRead($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Lädt alle Register und synchronisiert Auswahl
        $this->LoadRegisters();

        // Verarbeitet die ausgewählten Register
        $selectedRegisters = json_decode($this->ReadAttributeString("SavedRegisters"), true);
        foreach ($selectedRegisters as $register) {
            if ($register['selected'] ?? false) {
                $ident = "Addr" . $register['address'];
                if (!$this->GetIDForIdent($ident)) {
                    $this->RegisterVariableFloat(
                        $ident,
                        $register['name'],
                        $this->GetVariableProfile($register['unit']),
                        0
                    );
                }
            }
        }
    }

    public function RequestAction($Ident, $Value)
    {
        $this->SetValue($Ident, $Value);
    }

    private function LoadRegisters()
    {
        $allRegisters = $this->GetRegisters();
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);

        // Synchronisiere Auswahl mit existierenden Attributen
        $existingSelection = array_column($selectedRegisters, 'selected', 'address');
        $updatedRegisters = [];
        foreach ($allRegisters as $register) {
            $updatedRegisters[] = [
                'address'  => $register['address'],
                'name'     => $register['name'],
                'type'     => $register['type'],
                'unit'     => $register['unit'],
                'scale'    => $register['scale'],
                'selected' => $existingSelection[$register['address']] ?? false
            ];
        }

        // Speichere aktualisierte Register
        $this->UpdateFormField("SelectedRegisters", "values", json_encode($updatedRegisters));
        $this->WriteAttributeString("SavedRegisters", json_encode($updatedRegisters));
    }

    private function GetRegisters()
    {
        return [
            ["address" => 35103, "name" => "PV1 Voltage", "type" => "U16", "unit" => "V", "scale" => 10],
            ["address" => 35104, "name" => "PV1 Current", "type" => "U16", "unit" => "A", "scale" => 10],
            ["address" => 35191, "name" => "Total PV Energy", "type" => "U32", "unit" => "kWh", "scale" => 10],
            ["address" => 35107, "name" => "PV2 Voltage", "type" => "U16", "unit" => "V", "scale" => 10],
            ["address" => 36025, "name" => "Smartmeter Power", "type" => "S32", "unit" => "W", "scale" => 1]
        ];
    }

    private function GetVariableProfile(string $unit)
    {
        switch ($unit) {
            case "V":
                return "~Volt";
            case "A":
                return "~Ampere";
            case "W":
                return "~Watt";
            case "kWh":
                return "~Electricity";
            default:
                return "";
        }
    }
}
