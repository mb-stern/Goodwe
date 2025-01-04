<?php

declare(strict_types=1);

class Goodwe extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Verbinden mit ModBus
        $this->ConnectParent("{A5F663AB-C400-4FE5-B207-4D67CC030564}");
        
        // Eigenschaft für ausgewählte Register
        $this->RegisterPropertyString("SelectedRegisters", "[]");

        // Timer zur zyklischen Abfrage
        $this->RegisterTimer("Poller", 0, 'Goodwe_RequestRead($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Geladene Register einlesen
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);

        // Debugging
        $this->SendDebug("SelectedRegisters", json_encode($selectedRegisters), 0);

        foreach ($selectedRegisters as $register) {
            // Prüfen, ob alle nötigen Daten vorhanden sind
            if (!isset($register['address']) || !isset($register['name']) || !isset($register['unit'])) {
                $this->SendDebug("Error", "Fehlende Schlüssel im Register: " . json_encode($register), 0);
                continue;
            }

            $ident = "Addr" . $register['address'];

            // Variable erstellen, falls sie noch nicht existiert
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

    public function GetConfigurationForm()
    {
        // Register-Liste vorbereiten
        $registers = $this->GetRegisters();
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
        $existingSelection = array_column($selectedRegisters, 'selected', 'address');

        // Werte für das Formular
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

        return json_encode([
            "elements" => [
                [
                    "type"   => "List",
                    "name"   => "SelectedRegisters",
                    "caption" => "Register auswählen",
                    "add"    => false,
                    "delete" => false,
                    "columns" => [
                        ["caption" => "Adresse", "name" => "address", "width" => "100px"],
                        ["caption" => "Name", "name" => "name", "width" => "200px"],
                        ["caption" => "Typ", "name" => "type", "width" => "100px"],
                        ["caption" => "Einheit", "name" => "unit", "width" => "80px"],
                        ["caption" => "Skalierung", "name" => "scale", "width" => "80px"],
                        [
                            "caption" => "Auswählen",
                            "name"    => "selected",
                            "width"   => "100px",
                            "edit"    => ["type" => "CheckBox"]
                        ]
                    ],
                    "values" => $values
                ]
            ]
        ]);
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
