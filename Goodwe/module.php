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
    
        // Debug: Zeige die gespeicherten Daten vor Verarbeitung
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
        $this->SendDebug("ApplyChanges: SelectedRegisters Raw", json_encode($selectedRegisters), 0);
    
        // Sicherheitsprüfung
        if (!is_array($selectedRegisters)) {
            $this->SendDebug("ApplyChanges: Error", "SelectedRegisters ist keine gültige Liste", 0);
            return;
        }
    
        foreach ($selectedRegisters as $register) {
            // Debug: Zeige jeden Register-Eintrag
            $this->SendDebug("ApplyChanges: Processing Register", json_encode($register), 0);
    
            if (isset($register['selected']) && $register['selected']) {
                $ident = "Addr" . $register['address'];
                
                if (!$this->GetIDForIdent($ident)) {
                    $this->RegisterVariableFloat(
                        $ident,
                        $register['name'],
                        $this->GetVariableProfile($register['unit']),
                        0
                    );
                    $this->SendDebug("ApplyChanges: Variable Created", $ident, 0);
                }
            }
        }
    }
    
    public function GetConfigurationForm()
    {
        $this->SendDebug("GetConfigurationForm", "Start", 0);
    
        $registers = $this->GetRegisters();
        $this->SendDebug("Registers", json_encode($registers), 0);
    
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
        $this->SendDebug("SelectedRegisters Raw", json_encode($selectedRegisters), 0);
    
        $values = [];
        foreach ($registers as $register) {
            $entry = [
                "address"  => $register['address'],
                "name"     => $register['name'],
                "type"     => $register['type'],
                "unit"     => $register['unit'],
                "scale"    => $register['scale'],
                "selected" => false // Standardwert
            ];
    
            if (is_array($selectedRegisters)) {
                foreach ($selectedRegisters as $selectedRegister) {
                    if ($selectedRegister['address'] == $register['address']) {
                        $entry['selected'] = $selectedRegister['selected'];
                        break;
                    }
                }
            }
            $values[] = $entry;
            $this->SendDebug("Form Register Entry", json_encode($entry), 0);
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
    
        $this->SendDebug("GetConfigurationForm: Form Output", json_encode($form), 0);
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
