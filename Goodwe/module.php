<?php

declare(strict_types=1);

class Goodwe extends IPSModule
{
    public function Create()
    {
        // Never delete this line!
        parent::Create();
    
        // Verknüpfe mit dem übergeordneten Modbus-Gateway
        $this->ConnectParent("{A5F663AB-C400-4FE5-B207-4D67CC030564}");
    
        // Eigenschaft für die ausgewählten Register
        $this->RegisterPropertyString("SelectedRegisters", "[]");
    
        // Attribut für die verarbeiteten Register
        $this->RegisterAttributeString("ProcessedRegisters", "[]");
    
        // Timer zur zyklischen Abfrage
        $this->RegisterTimer("Poller", 0, 'Goodwe_RequestRead($_IPS["TARGET"]);');
    }
    
    public function ApplyChanges()
    {
        parent::ApplyChanges();
    
        // Lade die gespeicherten ausgewählten Register
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
    
        if (!is_array($selectedRegisters)) {
            $this->SendDebug("Error", "SelectedRegisters ist keine gültige Liste: " . $this->ReadPropertyString("SelectedRegisters"), 0);
            return;
        }
    
        // Debugging: Zeige den Inhalt von SelectedRegisters
        $this->SendDebug("ApplyChanges: Raw SelectedRegisters", json_encode($selectedRegisters), 0);
    
        foreach ($selectedRegisters as $register) {
            // Prüfen, ob die erforderlichen Schlüssel vorhanden sind
            if (!isset($register['address'], $register['name'], $register['unit'], $register['selected']) || !$register['selected']) {
                $this->SendDebug("Error", "Ein Register hat fehlende oder falsche Schlüssel: " . json_encode($register), 0);
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
                $this->SendDebug("ApplyChanges", "Variable erstellt: " . $register['name'], 0);
            } else {
                $this->SendDebug("ApplyChanges", "Variable existiert bereits: " . $register['name'], 0);
            }
        }
    }
    
    public function GetConfigurationForm()
    {
        $this->SendDebug("GetConfigurationForm", "Start loading configuration form", 0);
    
        $registers = $this->GetRegisters();
        $this->SendDebug("Registers", json_encode($registers), 0);
    
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
        if (!is_array($selectedRegisters)) {
            $this->SendDebug("Error", "SelectedRegisters ist keine gültige Liste: " . $this->ReadPropertyString("SelectedRegisters"), 0);
            $selectedRegisters = [];
        }
    
        $this->SendDebug("SelectedRegisters (decoded)", json_encode($selectedRegisters), 0);
    
        $existingSelection = array_column($selectedRegisters, 'selected', 'address');
        $this->SendDebug("ExistingSelection", json_encode($existingSelection), 0);
    
        $values = [];
        foreach ($registers as $register) {
            $entry = [
                "address"  => $register['address'],
                "name"     => $register['name'],
                "type"     => $register['type'],
                "unit"     => $register['unit'],
                "scale"    => $register['scale'],
                "selected" => $existingSelection[$register['address']] ?? false
            ];
            $values[] = $entry;
    
            $this->SendDebug("Register Entry", json_encode($entry), 0);
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
    
        $this->SendDebug("Form Output", json_encode($form), 0);
    
        return json_encode($form);
    }
    
    private function ReadRegister(int $address, string $type, float $scale)
    {
        $quantity = ($type === "U32" || $type === "S32") ? 2 : 1;

        $response = $this->SendDataToParent(json_encode([
            "DataID"   => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
            "Function" => 3,
            "Address"  => $address,
            "Quantity" => $quantity
        ]));

        if ($response === false || strlen($response) < (2 * $quantity + 2)) {
            $this->SendDebug("Error", "Keine oder unvollständige Antwort für Register $address", 0);
            return 0;
        }

        $data = unpack("n*", substr($response, 2));
        $value = 0;

        switch ($type) {
            case "U16":
                $value = $data[1];
                break;
            case "S16":
                $value = ($data[1] & 0x8000) ? -((~$data[1] & 0xFFFF) + 1) : $data[1];
                break;
            case "U32":
                $value = ($data[1] << 16) | $data[2];
                break;
            case "S32":
                $combined = ($data[1] << 16) | $data[2];
                $value = ($data[1] & 0x8000) ? -((~$combined & 0xFFFFFFFF) + 1) : $combined;
                break;
            default:
                $this->SendDebug("Error", "Unbekannter Typ für Register $address: $type", 0);
        }

        return $value / $scale;
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
                return ""; // Rückgabe eines Standardwerts, falls die Einheit nicht bekannt ist
        }
    }
}
