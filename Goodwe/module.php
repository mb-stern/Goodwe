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

        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);

        if (!is_array($selectedRegisters)) {
            $this->SendDebug("ApplyChanges", "SelectedRegisters ist keine gültige Liste", 0);
            return;
        }

        $this->SendDebug("ApplyChanges: SelectedRegisters", json_encode($selectedRegisters), 0);

        foreach ($selectedRegisters as $register) {
            if (isset($register['address'], $register['name'], $register['unit'])) {
                $ident = "Addr" . $register['address'];
                if (!@$this->GetIDForIdent($ident)) {
                    $this->RegisterVariableFloat(
                        $ident,
                        $register['name'],
                        $this->GetVariableProfile($register['unit']),
                        0
                    );
                    $this->SendDebug("ApplyChanges", "Variable erstellt: $ident mit Name {$register['name']}.", 0);
                }
            }
        }

        $pollInterval = $this->ReadPropertyInteger("PollInterval");
        $this->SetTimerInterval("Poller", $pollInterval * 1000);
    }

    public function RequestRead()
    {
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);

        foreach ($selectedRegisters as $register) {
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
    
        $registerOptions = array_map(function ($register) {
            return [
                "caption" => "{$register['address']} - {$register['name']}",
                "value" => $register['address']
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
                            "width" => "100px",
                            "add" => 0, // Standardwert für Address
                            "edit" => [
                                "type" => "Select",
                                "options" => $registerOptions
                            ]
                        ],
                        [
                            "caption" => "Name",
                            "name" => "name",
                            "width" => "200px",
                            "add" => "", // Standardwert für Name
                            "edit" => [
                                "type" => "ValidationTextBox"
                            ]
                        ],
                        [
                            "caption" => "Type",
                            "name" => "type",
                            "width" => "80px",
                            "add" => "U16", // Standardwert für Type
                            "edit" => [
                                "type" => "ValidationTextBox"
                            ]
                        ],
                        [
                            "caption" => "Unit",
                            "name" => "unit",
                            "width" => "80px",
                            "add" => "V", // Standardwert für Unit
                            "edit" => [
                                "type" => "ValidationTextBox"
                            ]
                        ],
                        [
                            "caption" => "Scale",
                            "name" => "scale",
                            "width" => "80px",
                            "add" => 10, // Standardwert für Scale
                            "edit" => [
                                "type" => "NumberSpinner"
                            ]
                        ]
                    ],
                    "values" => $selectedRegisters
                ],
                [
                    "type"  => "IntervalBox",
                    "name"  => "PollInterval",
                    "caption" => "Sekunden",
                    "suffix" => "seconds"
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
