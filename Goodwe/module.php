<?php
class Goodwe extends IPSModule
{
    public function __construct($InstanceID) {
        parent::__construct($InstanceID);
    }

    public function Create() {
        parent::Create();
        $this->ConnectParent("{A5F663AB-C400-4FE5-B207-4D67CC030564}");
        $this->RegisterPropertyInteger("Poller", 0);
        $this->RegisterTimer("Poller", 0, "Goodwe_RequestRead(\$_IPS['TARGET']);");
    }

    public function ApplyChanges() 
    {
        parent::ApplyChanges();
    
        // Variablen für jedes Register erstellen
        foreach ($this->Registers() as $register) {
            $ident = "Addr" . $register['address'];
    
            // Prüfen, ob der Ident schon existiert
            if (@$this->GetIDForIdent($ident) === false) {
                $profileInfo = $this->GetVariableProfile($register['unit'], $register['scale']);
    
                // Variable registrieren basierend auf Typ
                switch ($profileInfo['type']) {
                    case VARIABLETYPE_FLOAT:
                        $this->RegisterVariableFloat(
                            $ident,
                            $register['name'], // Frei definierbarer Name aus der Tabelle
                            $profileInfo['profile'],
                            0
                        );
                        break;
    
                    case VARIABLETYPE_INTEGER:
                        $this->RegisterVariableInteger(
                            $ident,
                            $register['name'], // Frei definierbarer Name aus der Tabelle
                            $profileInfo['profile'],
                            0
                        );
                        break;
    
                    case VARIABLETYPE_STRING:
                        $this->RegisterVariableString(
                            $ident,
                            $register['name'], // Frei definierbarer Name aus der Tabelle
                            $profileInfo['profile'],
                            0
                        );
                        break;
    
                    case VARIABLETYPE_BOOLEAN:
                        $this->RegisterVariableBoolean(
                            $ident,
                            $register['name'], // Frei definierbarer Name aus der Tabelle
                            $profileInfo['profile'],
                            0
                        );
                        break;
                }
    
                // Falls Aktionen benötigt werden
                if ($register['action']) {
                    $this->EnableAction($ident);
                }
            } else {
                $this->SendDebug("Info", "Variable mit Ident $ident existiert bereits.", 0);
            }
        }
    
        $this->SetTimerInterval("Poller", $this->ReadPropertyInteger("Poller"));
    }
    
    private function GetVariableProfile(string $unit, float $scale): array
    {
        switch ($unit) {
            case "V":
                return ["profile" => "~Volt", "type" => VARIABLETYPE_FLOAT];
            case "A":
                return ["profile" => "~Ampere", "type" => VARIABLETYPE_FLOAT];
            case "W":
                return ["profile" => "~Watt", "type" => VARIABLETYPE_FLOAT];
            case "Hz":
                return ["profile" => "~Hertz", "type" => VARIABLETYPE_FLOAT];
            case "°C":
                return ["profile" => "~Temperature", "type" => VARIABLETYPE_FLOAT];
            case "kWh":
                return ["profile" => "~Electricity", "type" => VARIABLETYPE_FLOAT];
            case "%":
                return ["profile" => "~Battery.100", "type" => VARIABLETYPE_INTEGER];
            case "N/A":
                return ["profile" => "", "type" => VARIABLETYPE_INTEGER];
            default:
                return ["profile" => "", "type" => VARIABLETYPE_FLOAT];
        }
    }

    public function RequestRead()
    {
        foreach ($this->Registers() as $register) {
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
    
    private function Registers()
    {
        return [
            ["address" => 35103, "name" => "PV1 Voltage",    "type" => "U16", "unit" => "V",   "scale" => 10, "quantity" => 1, "readOnly" => true, "action" => false],
            ["address" => 35104, "name" => "PV1 Current",    "type" => "U16", "unit" => "A",   "scale" => 10, "quantity" => 1, "readOnly" => true, "action" => false],
            ["address" => 35191, "name" => "Total PV Energy","type" => "U32", "unit" => "kWh", "scale" => 10, "quantity" => 2, "readOnly" => true, "action" => false],
            ["address" => 35107, "name" => "PV2 Voltage",    "type" => "U16", "unit" => "V",   "scale" => 10, "quantity" => 1, "readOnly" => true, "action" => false],
            ["address" => 36025, "name" => "Smartmeter Power","type" => "S32", "unit" => "W",  "scale" => 1,  "quantity" => 2, "readOnly" => true, "action" => false]
        ];
    }

}
