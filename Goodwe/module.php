<?php

declare(strict_types=1);

class Goodwe extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->ConnectParent("{A5F663AB-C400-4FE5-B207-4D67CC030564}");
        $this->RegisterAttributeString("SelectedRegisters", "[]");
        $this->RegisterPropertyString("SelectedRegisters", "[]");

        // Timer zur zyklischen Abfrage
        $this->RegisterTimer("Poller", 0, 'Goodwe_RequestRead($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Debug: Zeigt die aktuellen Attribute und Properties
        $this->SendDebug("ApplyChanges", "Current SelectedRegisters: " . $this->ReadAttributeString("SelectedRegisters"), 0);
        $this->SendDebug("ApplyChanges", "Form Property SelectedRegisters: " . $this->ReadPropertyString("SelectedRegisters"), 0);

        $this->SyncSelectedRegisters();
        $this->CreateVariablesFromSelection();
        $this->LoadRegisters();
    }

    private function SyncSelectedRegisters()
    {
        $formValues = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
        $registers = $this->GetRegisters();
        $updatedRegisters = [];

        $this->SendDebug("SyncSelectedRegisters", "Form Values: " . json_encode($formValues), 0);

        foreach ($registers as $register) {
            $updatedRegister = $register;
            $updatedRegister['selected'] = false; // Standardmäßig nicht ausgewählt
            foreach ($formValues as $formValue) {
                if (isset($formValue['address']) && $formValue['address'] == $register['address']) {
                    $updatedRegister['selected'] = $formValue['selected'];
                }
            }
            $updatedRegisters[] = $updatedRegister;
        }

        $this->SendDebug("SyncSelectedRegisters", "Updated Registers: " . json_encode($updatedRegisters), 0);
        $this->WriteAttributeString("SelectedRegisters", json_encode($updatedRegisters));
    }

    private function CreateVariablesFromSelection()
    {
        $selectedRegisters = json_decode($this->ReadAttributeString("SelectedRegisters"), true);

        foreach ($selectedRegisters as $register) {
            if (isset($register['selected']) && $register['selected']) {
                $ident = "Addr" . $register['address'];
                if (!$this->GetIDForIdent($ident)) {
                    $this->SendDebug("CreateVariablesFromSelection", "Creating variable for: " . json_encode($register), 0);
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

    private function LoadRegisters()
    {
        $registers = $this->GetRegisters();
        $selectedRegisters = json_decode($this->ReadAttributeString("SelectedRegisters"), true);
        $existingSelection = array_column($selectedRegisters, 'selected', 'address');

        $values = [];
        foreach ($registers as $register) {
            $values[] = [
                "address"  => $register['address'],
                "name"     => $register['name'],
                "type"     => $register['type'],
                "unit"     => $register['unit'],
                "scale"    => $register['scale'],
                "selected" => $existingSelection[$register['address']] ?? false
            ];
        }

        $this->SendDebug("LoadRegisters", "Loaded Registers: " . json_encode($values), 0);
        $this->UpdateFormField("SelectedRegisters", "values", json_encode($values));
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
