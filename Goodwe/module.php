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

        // Lese die ausgewählten Register
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
        if (!is_array($selectedRegisters)) {
            $this->SendDebug("ApplyChanges", "SelectedRegisters ist keine gültige Liste", 0);
            return;
        }
    
        foreach ($selectedRegisters as &$selectedRegister) {
            // Dekodiere verschachtelte JSON-Einträge
            if (is_string($selectedRegister['address'])) {
                $decodedRegister = json_decode($selectedRegister['address'], true);
                if ($decodedRegister !== null) {
                    $selectedRegister = array_merge($selectedRegister, $decodedRegister);
                } else {
                    $this->SendDebug("ApplyChanges", "Ungültiger JSON-String für Address: " . $selectedRegister['address'], 0);
                    continue;
                }
            }
    
            // Erstelle Variablen
            if (!isset($selectedRegister['address'], $selectedRegister['name'], $selectedRegister['unit'])) {
                $this->SendDebug("ApplyChanges", "Fehlende Felder im Register: " . json_encode($selectedRegister), 0);
                continue;
            }
    
            $ident = "Addr" . $selectedRegister['address'];
            if (!@$this->GetIDForIdent($ident)) {
                $this->RegisterVariableFloat(
                    $ident,
                    $selectedRegister['name'],
                    $this->GetVariableProfile($selectedRegister['unit']),
                    0
                );
                $this->SendDebug("ApplyChanges", "Variable erstellt: $ident mit Name {$selectedRegister['name']}.", 0);
            }
        }
    
        $pollInterval = $this->ReadPropertyInteger("PollInterval");
        $this->SetTimerInterval("Poller", $pollInterval * 1000);
        $this->RequestAction($Ident, $Value);
    }

    public function RequestAction($Ident, $Value)
    {
        $this->RequestRead();
    }

    public function RequestRead()
    {
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
        if (!is_array($selectedRegisters)) {
            $this->SendDebug("RequestRead", "SelectedRegisters ist keine gültige Liste", 0);
            return;
        }
    
        foreach ($selectedRegisters as &$register) {
            if (is_string($register['address'])) {
                $decodedRegister = json_decode($register['address'], true);
                if ($decodedRegister !== null) {
                    $register = array_merge($register, $decodedRegister);
                } else {
                    $this->SendDebug("RequestRead", "Ungültiger JSON-String für Address: " . $register['address'], 0);
                    continue;
                }
            }
    
            // Validierung der Felder
            if (!isset($register['address'], $register['type'], $register['scale'])) {
                $this->SendDebug("RequestRead", "Ungültiger Registereintrag: " . json_encode($register), 0);
                continue;
            }
    
            $ident = "Addr" . $register['address'];
            $quantity = ($register['type'] === "U32" || $register['type'] === "S32") ? 2 : 1;
    
            $response = $this->SendDataToParent(json_encode([
                "DataID"   => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
                "Function" => 3,
                "Address"  => $register['address'],
                "Quantity" => $quantity,
                "Data"     => ""
            ]));
    
            if ($response === false || strlen($response) < (2 * $quantity + 2)) {
                $this->SendDebug("RequestRead", "Keine oder unvollständige Antwort für Register {$register['address']}", 0);
                continue;
            }
    
            $data = unpack("n*", substr($response, 2));
            $value = 0;
    
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
    
            if ($register['scale'] == 0) {
                $this->SendDebug("RequestRead", "Division durch Null für Register {$register['address']}", 0);
                continue;
            }
    
            $scaledValue = $value / $register['scale'];
    
            $variableID = @$this->GetIDForIdent($ident);
            if ($variableID === false) {
                $this->SendDebug("RequestRead", "Variable mit Ident $ident nicht gefunden.", 0);
                continue;
            }
    
            SetValue($variableID, $scaledValue);
            $this->SendDebug("RequestRead", "Wert für Register {$register['address']}: $scaledValue", 0);
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
                "value" => json_encode($register) // Speichert das gesamte Register als JSON
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
                            "width" => "300px",
                            "add" => '',
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
