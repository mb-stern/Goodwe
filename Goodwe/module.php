<?php

declare(strict_types=1);

class Goodwe extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->ConnectParent("{A5F663AB-C400-4FE5-B207-4D67CC030564}");
        $this->RegisterPropertyString("Registers", json_encode($this->GetRegisters()));
        $this->RegisterPropertyString("SelectedRegisters", "[]");
        $this->RegisterPropertyInteger("PollInterval", 60); // Standard: 60 Sekunden

        $this->RegisterTimer("Poller", 0, 'Goodwe_RequestRead($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    
        // Lese die ausgewählten Register aus
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
    
        // Debugging
        $this->SendDebug("ApplyChanges: SelectedRegisters", json_encode($selectedRegisters), 0);
    
        foreach ($selectedRegisters as $register) {
            if (isset($register['address'], $register['name'], $register['unit'])) {
                $ident = "Addr" . $register['address'];
    
                // Prüfen, ob die Variable existiert
                if (!@$this->GetIDForIdent($ident)) {
                    $this->RegisterVariableFloat(
                        $ident,
                        $register['name'],
                        $this->GetVariableProfile($register['unit']),
                        0
                    );
                    $this->SendDebug("ApplyChanges", "Variable erstellt: $ident mit Name {$register['name']}.", 0);
                } else {
                    $this->SendDebug("ApplyChanges", "Variable mit Ident $ident existiert bereits.", 0);
                }
            }
        }
    
        // Timer setzen
        $pollInterval = $this->ReadPropertyInteger("PollInterval");
        $this->SetTimerInterval("Poller", $pollInterval * 1000);
    }
    
    public function RequestRead()
    {
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
        foreach ($selectedRegisters as $register) {
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
    

    public function GetConfigurationForm()
    {
        $registers = $this->GetRegisters();
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
    
        // Erstellen der Optionen für die Auswahlliste
        $registerOptions = array_map(function ($register) {
            return [
                "caption" => "{$register['address']} - {$register['name']}",
                "value" => json_encode($register) // Speichern des gesamten Objekts als JSON
            ];
        }, $registers);
    
        return json_encode([
            "elements" => [
                [
                    "type"  => "List",
                    "name"  => "SelectedRegisters",
                    "caption" => "Selected Registers",
                    "rowCount" => 10,
                    "add" => true,
                    "delete" => true,
                    "columns" => [
                        [
                            "caption" => "Address",
                            "name" => "address",
                            "width" => "200px",
                            "add" => "", // Standardwert beim Hinzufügen
                            "edit" => [
                                "type" => "Select",
                                "options" => $registerOptions
                            ]
                        ]
                    ],
                    "values" => $selectedRegisters
                ],
                [
                    "type"  => "IntervalBox",
                    "name"  => "PollInterval",
                    "caption" => "Abfrageintervall (Sekunden)",
                    "suffix" => "s"
                ]
            ],
            "actions" => [
                [
                    "type" => "Button",
                    "caption" => "Werte lesen",
                    "onClick" => 'Goodwe_RequestRead($id);'
                ]
            ]
        ]);
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
