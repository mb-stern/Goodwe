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
        $this->RegisterPropertyInteger("PollInterval", 5); // Standard: 60 Sekunden

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
    
        // Liste der aktuellen Register-Identifikatoren
        $currentIdents = [];
    
        foreach ($selectedRegisters as &$selectedRegister) {
            if (is_string($selectedRegister['address'])) {
                $decodedRegister = json_decode($selectedRegister['address'], true);
                if ($decodedRegister !== null) {
                    $selectedRegister = array_merge($selectedRegister, $decodedRegister);
                } else {
                    $this->SendDebug("ApplyChanges", "Ungültiger JSON-String für Address: " . $selectedRegister['address'], 0);
                    continue;
                }
            }
    
            $variableDetails = $this->GetVariableDetails($selectedRegister['unit']);
            if ($variableDetails === null) {
                $this->SendDebug("ApplyChanges", "Kein Profil oder Typ für Einheit {$selectedRegister['unit']} gefunden.", 0);
                continue;
            }
    
            $ident = "Addr" . $selectedRegister['address'];
            $currentIdents[] = $ident;
    
            if (!@$this->GetIDForIdent($ident)) {
                switch ($variableDetails['type']) {
                    case VARIABLETYPE_INTEGER:
                        $this->RegisterVariableInteger($ident, $selectedRegister['name'], $variableDetails['profile'], 0);
                        break;
                    case VARIABLETYPE_FLOAT:
                        $this->RegisterVariableFloat($ident, $selectedRegister['name'], $variableDetails['profile'], 0);
                        break;
                    case VARIABLETYPE_STRING:
                        $this->RegisterVariableString($ident, $selectedRegister['name'], $variableDetails['profile'], 0);
                        break;
                    default:
                        $this->SendDebug("ApplyChanges", "Unbekannter Variablentyp für {$selectedRegister['unit']}.", 0);
                        continue 2;
                }
                $this->SendDebug("ApplyChanges", "Variable erstellt: $ident mit Name {$selectedRegister['name']} und Profil {$variableDetails['profile']}.", 0);
            } else {
                $this->SendDebug("ApplyChanges", "Variable mit Ident $ident existiert bereits.", 0);
            }
        }
    
        // Variablen löschen, die nicht mehr in der aktuellen Liste sind
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            $object = IPS_GetObject($childID);
            if ($object['ObjectType'] === OBJECTTYPE_VARIABLE && !in_array($object['ObjectIdent'], $currentIdents)) {
                $this->UnregisterVariable($object['ObjectIdent']);
                $this->SendDebug("ApplyChanges", "Variable mit Ident {$object['ObjectIdent']} gelöscht.", 0);
            }
        }
    
        // Timer setzen
        $pollInterval = $this->ReadPropertyInteger("PollInterval");
        $this->SetTimerInterval("Poller", $pollInterval * 1000);
        $this->RequestRead();
    }
    

    public function RequestRead()
    {
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
        if (!is_array($selectedRegisters)) {
            $this->SendDebug("RequestRead", "SelectedRegisters ist keine gültige Liste", 0);
            return;
        }
    
        // Prüfen, ob eine Verbindung zum Parent besteht
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentID === 0 || !IPS_InstanceExists($parentID)) {
            $this->SendDebug("RequestRead", "Keine gültige Parent-Instanz verbunden.", 0);
            IPS_LogMessage("Goodwe", "Keine gültige Parent-Instanz verbunden. RequestRead abgebrochen.");
            return;
        }
    
        // Prüfen, ob der Parent geöffnet ist (sofern relevant für den Parent-Typ)
        $parentStatus = IPS_GetInstance($parentID)['InstanceStatus'];
        if ($parentStatus !== IS_ACTIVE) {
            $this->SendDebug("RequestRead", "Parent-Instanz ist nicht aktiv. Status: $parentStatus", 0);
            IPS_LogMessage("Goodwe", "Parent-Instanz ist nicht aktiv. RequestRead abgebrochen.");
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
    
            try {
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
            } catch (Exception $e) {
                $this->SendDebug("RequestRead", "Fehler bei Kommunikation mit Parent: " . $e->getMessage(), 0);
                IPS_LogMessage("Goodwe", "Fehler bei Kommunikation mit Parent: " . $e->getMessage());
            }
        }
    }
    
    public function GetConfigurationForm()
    {
        $registers = $this->GetRegisters();
        IPS_SetProperty($this->InstanceID, "Registers", json_encode($registers));
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
    

    private function GetVariableDetails(string $unit): ?array
    {
        switch ($unit) {
            case "V":
                return ["profile" => "~Volt", "type" => VARIABLETYPE_FLOAT];
            case "A":
                return ["profile" => "~Ampere", "type" => VARIABLETYPE_FLOAT];
            case "W":
                return ["profile" => "~Watt", "type" => VARIABLETYPE_FLOAT];
            case "kWh":
                return ["profile" => "~Electricity", "type" => VARIABLETYPE_FLOAT];
            case "°C":
                return ["profile" => "~Temperature", "type" => VARIABLETYPE_FLOAT];
            case "%":
                return ["profile" => "~Battery.100", "type" => VARIABLETYPE_INTEGER];
            case "String":
                return ["profile" => "~String", "type" => VARIABLETYPE_STRING];
            default:
                return null; // Kein bekanntes Profil oder Typ
        }
    }
    
    
    private function GetRegisters()
    {
        return [
            ["address" => 35103, "name" => "PV1 Voltage", "type" => "U16", "unit" => "V", "scale" => 10],
            ["address" => 35104, "name" => "PV1 Current", "type" => "U16", "unit" => "A", "scale" => 10],
            ["address" => 35191, "name" => "Total PV Energy", "type" => "U32", "unit" => "kWh", "scale" => 10],
            ["address" => 35107, "name" => "PV2 Voltage", "type" => "U16", "unit" => "V", "scale" => 10],
            ["address" => 36025, "name" => "Smartmeter Power", "type" => "S32", "unit" => "W", "scale" => 1],
            ["address" => 35182, "name" => "Batterie Leistung", "type" => "S32", "unit" => "W", "scale" => 1],
            ["address" => 47908, "name" => "State of Charge (SOC)", "type" => "S16", "unit" => "%", "scale" => 1],
            ["address" => 37003, "name" => "BMS Temperatur", "type" => "S16", "unit" => "°C", "scale" => 1]
        ];
    }
}
