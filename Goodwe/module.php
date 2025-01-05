<?php

declare(strict_types=1);

class Goodwe extends IPSModule
{
    public function Create()
    {
        parent::Create();
    
        // Initialisiere die Properties
        $this->ConnectParent("{A5F663AB-C400-4FE5-B207-4D67CC030564}");
        $this->RegisterPropertyString("Registers", json_encode($this->GetRegisters()));
        $this->RegisterPropertyString("SelectedRegisters", json_encode([]));
        $this->RegisterPropertyInteger("PollInterval", 60); // Standard 60 Sekunden
    
        // Timer zur zyklischen Abfrage
        $this->RegisterTimer("Poller", 0, 'Goodwe_RequestRead($_IPS["TARGET"]);');
    }
    
    public function RequestAction($ident, $value)
    {
        if ($ident === "AddRegister") {
            // Lade die aktuelle Liste der ausgewählten Register
            $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
            if (!is_array($selectedRegisters)) {
                $selectedRegisters = [];
            }
    
            // Neues Register hinzufügen
            $newRegister = json_decode($value, true);
            if (is_array($newRegister)) {
                foreach ($selectedRegisters as $register) {
                    if ($register['address'] === $newRegister['address']) {
                        $this->SendDebug("RequestAction", "Register bereits hinzugefügt: " . json_encode($newRegister), 0);
                        return; // Duplikat, nichts tun
                    }
                }
    
                $selectedRegisters[] = $newRegister;
    
                // Property aktualisieren
                IPS_SetProperty($this->InstanceID, "SelectedRegisters", json_encode($selectedRegisters));
    
                // Änderungen anwenden
                IPS_ApplyChanges($this->InstanceID);
            }
        }
    }
    
    public function ApplyChanges()
    {
        parent::ApplyChanges();
    
        // Lese die ausgewählten Register
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
        $this->SendDebug("ApplyChanges: SelectedRegisters", json_encode($selectedRegisters), 0);
    
        // Variablen für die ausgewählten Register erstellen
        foreach ($selectedRegisters as $register) {
            if (isset($register['address'], $register['name'], $register['unit'])) {
                $ident = "Addr" . $register['address'];
    
                $varId = @$this->GetIDForIdent($ident);
                if ($varId === false) {
                    $this->RegisterVariableFloat(
                        $ident,
                        $register['name'],
                        $this->GetVariableProfile($register['unit']),
                        0
                    );
                    $this->SendDebug("ApplyChanges", "Variable mit Ident $ident erstellt.", 0);
                } else {
                    $this->SendDebug("ApplyChanges", "Variable mit Ident $ident existiert bereits.", 0);
                }
            } else {
                $this->SendDebug("ApplyChanges", "Ungültiges Register: " . json_encode($register), 0);
            }
        }
    
        // Timer setzen
        $pollInterval = $this->ReadPropertyInteger("PollInterval");
        $this->SetTimerInterval("Poller", $pollInterval * 1000);
    }
    
    public function GetConfigurationForm()
    {
        $registers = $this->GetRegisters();
        $this->SendDebug("GetConfigurationForm: Registers", json_encode($registers), 0);
    
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
        $this->SendDebug("GetConfigurationForm: SelectedRegisters", json_encode($selectedRegisters), 0);
    
        $registerOptions = [];
        foreach ($registers as $register) {
            $registerOptions[] = [
                "caption" => "{$register['address']} - {$register['name']}",
                "value" => json_encode($register)
            ];
        }
    
        return json_encode([
            "elements" => [
                [
                    "type"  => "List",
                    "name"  => "SelectedRegisters",
                    "caption" => "Selected Registers",
                    "rowCount" => 10,
                    "add" => false,
                    "delete" => true,
                    "columns" => [
                        ["caption" => "Address", "name" => "address", "width" => "100px"],
                        ["caption" => "Name", "name" => "name", "width" => "200px"],
                        ["caption" => "Type", "name" => "type", "width" => "80px"],
                        ["caption" => "Unit", "name" => "unit", "width" => "80px"],
                        ["caption" => "Scale", "name" => "scale", "width" => "80px"]
                    ],
                    "values" => $selectedRegisters
                ],
                [
                    "type" => "Select",
                    "name" => "AvailableRegisters",
                    "caption" => "Add Register",
                    "options" => $registerOptions
                ],
                [
                    "type" => "Button",
                    "caption" => "Hinzufügen",
                    "onClick" => 'IPS_RequestAction($id, "AddRegister", $AvailableRegisters);'
                ],
                [
                    "type"  => "NumberSpinner",
                    "name"  => "PollInterval",
                    "caption" => "Poll Interval (seconds)",
                    "suffix" => "seconds"
                ]
            ]
        ]);
        
    }

    public function RequestRead()
    {
        foreach ($this->ReadPropertyString("SelectedRegisters") as $register) {
            $ident = "Addr" . $register['address'];
    
            // Modbus-Anfrage senden
            $quantity = ($register['type'] === "U32" || $register['type'] === "S32") ? 2 : 1;
    
            $response = $this->SendDataToParent(json_encode([
                "DataID"   => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
                "Function" => 3,
                "Address"  => $register['address'],
                "Quantity" => $quantity,
                "Data"     => ""
            ]));
    
            // Fehlerbehandlung
            if ($response === false || strlen($response) < (2 * $quantity + 2)) {
                $this->SendDebug("Error", "No or incomplete response for Register {$register['address']}", 0);
                continue;
            }
    
            // Antwortdaten extrahieren
            $data = unpack("n*", substr($response, 2));
            $value = 0;
    
            // Werte basierend auf Typ interpretieren
            switch ($register['type']) {
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
            }
    
            $scaledValue = $value / $register['scale'];
    
            // Prüfen, ob Variable existiert, bevor der Wert geschrieben wird
            $variableID = @$this->GetIDForIdent($ident);
            if ($variableID === false) {
                $this->SendDebug("Error", "Variable mit Ident $ident wurde nicht gefunden.", 0);
                continue;
            }
    
            SetValue($variableID, $scaledValue);
            $this->SendDebug("Parsed Value for Register {$register['address']}", $scaledValue, 0);
        }
    }


    private function GetRegisters()
    {
        $registers = [
            ["address" => 35103, "name" => "PV1 Voltage", "type" => "U16", "unit" => "V", "scale" => 10],
            ["address" => 35104, "name" => "PV1 Current", "type" => "U16", "unit" => "A", "scale" => 10],
            ["address" => 35191, "name" => "Total PV Energy", "type" => "U32", "unit" => "kWh", "scale" => 10],
            ["address" => 35107, "name" => "PV2 Voltage", "type" => "U16", "unit" => "V", "scale" => 10],
            ["address" => 36025, "name" => "Smartmeter Power", "type" => "S32", "unit" => "W", "scale" => 1]
        ];
        $this->SendDebug("GetRegisters", json_encode($registers), 0);
        return $registers;
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
                return ""; // Fallback
        }
    }
}
