<?php

declare(strict_types=1);

class Goodwe extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Verknüpfe mit dem Modbus-Gateway
        $this->ConnectParent("{A5F663AB-C400-4FE5-B207-4D67CC030564}");

        // Eigenschaft für die ausgewählten Register
        $this->RegisterPropertyString("SelectedRegisters", json_encode([]));

        // Timer zur zyklischen Abfrage
        $this->RegisterTimer("Poller", 0, 'Goodwe_RequestRead($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Lese die ausgewählten Register aus der Property
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
        $this->SendDebug("ApplyChanges: SelectedRegisters", json_encode($selectedRegisters), 0);

        if (!is_array($selectedRegisters)) {
            $this->SendDebug("Error", "SelectedRegisters ist keine gültige Liste: " . $this->ReadPropertyString("SelectedRegisters"), 0);
            return;
        }

        foreach ($selectedRegisters as $register) {
            if (isset($register['address']) && $register['selected']) {
                $ident = "Addr" . $register['address'];

                if (!$this->GetIDForIdent($ident)) {
                    $this->RegisterVariableFloat(
                        $ident,
                        $register['name'] ?? "Unbekannt",
                        $this->GetVariableProfile($register['unit'] ?? ""),
                        0
                    );
                }
            }
        }
    }

    public function GetConfigurationForm()
    {
        $registers = $this->GetRegisters();
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);

        $this->SendDebug("GetConfigurationForm: SelectedRegisters", json_encode($selectedRegisters), 0);

        $values = [];
        foreach ($registers as $register) {
            $isSelected = false;

            foreach ($selectedRegisters as $selectedRegister) {
                if (isset($selectedRegister['address']) && $selectedRegister['address'] === $register['address'] && $selectedRegister['selected']) {
                    $isSelected = true;
                    break;
                }
            }

            $values[] = [
                "address"  => $register['address'],
                "name"     => $register['name'],
                "type"     => $register['type'],
                "unit"     => $register['unit'],
                "scale"    => $register['scale'],
                "selected" => $isSelected
            ];
        }

        $form = [
            "elements" => [
                [
                    "type"  => "List",
                    "name"  => "SelectedRegisters",
                    "caption" => "Register",
                    "add"   => false,
                    "delete" => false,
                    "columns" => [
                        ["caption" => "Address", "name" => "address", "width" => "100px"],
                        ["caption" => "Name", "name" => "name", "width" => "200px"],
                        ["caption" => "Type", "name" => "type", "width" => "80px"],
                        ["caption" => "Unit", "name" => "unit", "width" => "80px"],
                        ["caption" => "Scale", "name" => "scale", "width" => "80px"],
                        ["caption" => "Selected", "name" => "selected", "width" => "80px", "edit" => ["type" => "CheckBox"]]
                    ],
                    "values" => $values
                ]
            ]
        ];

        $this->SendDebug("GetConfigurationForm: Full Output", json_encode($form), 0);
        return json_encode($form);
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
