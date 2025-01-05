<?php

declare(strict_types=1);

class Goodwe extends IPSModule
{
    public function Create()
    {
        parent::Create();
    
        // Initialisiere die Property mit den verfügbaren Registern
        $this->RegisterPropertyString("Registers", json_encode($this->GetRegisters()));
        $this->RegisterPropertyString("SelectedRegisters", json_encode([]));
    
        // Timer zur zyklischen Abfrage
        $this->RegisterTimer("Poller", 0, 'Goodwe_RequestRead($_IPS["TARGET"]);');
    }
    
    
    public function ApplyChanges()
    {
        parent::ApplyChanges();
    
        // Lese die ausgewählten Register aus der Property
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
    
        // Debugging
        $this->SendDebug("ApplyChanges: SelectedRegisters", json_encode($selectedRegisters), 0);
    
        if (!is_array($selectedRegisters)) {
            $this->SendDebug("Error", "SelectedRegisters ist ungültig", 0);
            return;
        }
    
        // Variablen für alle ausgewählten Register erstellen
        foreach ($selectedRegisters as $register) {
            if (isset($register['address'], $register['name'], $register['unit'])) {
                $ident = "Addr" . $register['address'];
    
                // Überprüfen, ob die Variable existiert
                if (!$this->GetIDForIdent($ident)) {
                    $this->RegisterVariableFloat(
                        $ident,
                        $register['name'],
                        $this->GetVariableProfile($register['unit']),
                        0
                    );
                }
            } else {
                $this->SendDebug("ApplyChanges: Ungültiges Register", json_encode($register), 0);
            }
        }
    }
    
    
    public function GetConfigurationForm()
    {
        $registers = $this->GetRegisters();
        $this->SendDebug("GetConfigurationForm: Registers", json_encode($registers), 0);
    
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
        $this->SendDebug("GetConfigurationForm: SelectedRegisters", json_encode($selectedRegisters), 0);
    
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
                            "width" => "100px",
                            "edit" => ["type" => "NumberSpinner"],
                            "add" => 0 // Standardwert für Address
                        ],
                        [
                            "caption" => "Name",
                            "name" => "name",
                            "width" => "200px",
                            "edit" => ["type" => "ValidationTextBox"],
                            "add" => "" // Standardwert für Name
                        ],
                        [
                            "caption" => "Type",
                            "name" => "type",
                            "width" => "80px",
                            "edit" => [
                                "type" => "Select",
                                "options" => [
                                    ["caption" => "U16", "value" => "U16"],
                                    ["caption" => "S16", "value" => "S16"],
                                    ["caption" => "U32", "value" => "U32"],
                                    ["caption" => "S32", "value" => "S32"]
                                ]
                            ],
                            "add" => "U16" // Standardwert für Type
                        ],
                        [
                            "caption" => "Unit",
                            "name" => "unit",
                            "width" => "80px",
                            "edit" => ["type" => "ValidationTextBox"],
                            "add" => "" // Standardwert für Unit
                        ],
                        [
                            "caption" => "Scale",
                            "name" => "scale",
                            "width" => "80px",
                            "edit" => ["type" => "NumberSpinner"],
                            "add" => 1 // Standardwert für Scale
                        ]
                    ],
                    "values" => json_decode($this->ReadPropertyString("SelectedRegisters"), true)
                ]
            ]
        ]);
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
