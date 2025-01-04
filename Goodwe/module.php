<?php

declare(strict_types=1);

class Goodwe extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->ConnectParent("{A5F663AB-C400-4FE5-B207-4D67CC030564}");
        $this->RegisterPropertyString("SelectedRegisters", "[]");
        $this->RegisterAttributeString("SelectedRegistersCache", "[]");

        $this->RegisterTimer("Poller", 0, 'Goodwe_RequestRead($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    
        // Lade die gespeicherten ausgewählten Register
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
    
        // Debugging: Zeige den Inhalt von SelectedRegisters an
        $this->SendDebug("SelectedRegisters", json_encode($selectedRegisters), 0);
    
        foreach ($selectedRegisters as $register) {
            // Prüfe, ob die erforderlichen Schlüssel vorhanden sind
            if (!isset($register['address']) || !isset($register['name']) || !isset($register['unit'])) {
                $this->SendDebug("Error", "Ein Register hat fehlende Schlüssel: " . json_encode($register), 0);
                continue;
            }
    
            $ident = "Addr" . $register['address'];
    
            // Prüfen, ob die Variable existiert, und falls nicht, erstellen
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
                return ""; // Rückgabe eines Standardwerts, falls die Einheit nicht bekannt ist
        }
    }
    
    public function GetConfigurationForm()
    {
        // Lade die Register dynamisch
        $registers = $this->GetRegisters();
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
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
}
