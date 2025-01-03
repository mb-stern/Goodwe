<?php
class Goodwe extends IPSModule
{
    public function __construct($InstanceID) {

        //Never delete this line!
        parent::__construct($InstanceID);

        }
    public function Create() {
		
        //Never delete this line!
        parent::Create();
        
        $this->ConnectParent("{A5F663AB-C400-4FE5-B207-4D67CC030564}");
        
        $this->RegisterPropertyInteger("Poller", 0);
        $this->RegisterPropertyInteger("Phase", 1);
        
        $this->RegisterTimer("Poller", 0, "Goodwe_RequestRead(\$_IPS['TARGET']);");

    }

    public function ApplyChanges() 
    {
        parent::ApplyChanges();
    
        // Variablen für jedes Register erstellen
        foreach ($this->Registers() as $register) {
            $profileInfo = $this->GetVariableProfile($register['unit'], $register['scale']);
    
            // Variable registrieren basierend auf Typ
            switch ($profileInfo['type']) {
                case VARIABLETYPE_FLOAT:
                    $this->RegisterVariableFloat(
                        $register['name'],
                        $register['name'] . " (" . $register['unit'] . ")",
                        $profileInfo['profile'],
                        0
                    );
                    break;
    
                case VARIABLETYPE_INTEGER:
                    $this->RegisterVariableInteger(
                        $register['name'],
                        $register['name'] . " (" . $register['unit'] . ")",
                        $profileInfo['profile'],
                        0
                    );
                    break;
    
                case VARIABLETYPE_STRING:
                    $this->RegisterVariableString(
                        $register['name'],
                        $register['name'] . " (" . $register['unit'] . ")",
                        $profileInfo['profile'],
                        0
                    );
                    break;
    
                case VARIABLETYPE_BOOLEAN:
                    $this->RegisterVariableBoolean(
                        $register['name'],
                        $register['name'] . " (" . $register['unit'] . ")",
                        $profileInfo['profile'],
                        0
                    );
                    break;
            }
    
            // Falls Aktionen benötigt werden
            if ($register['action']) {
                $this->EnableAction($register['name']);
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
            case "N/A": // Beispiel für Integer-Werte ohne Einheit
                return ["profile" => "", "type" => VARIABLETYPE_INTEGER];
            default:
                return ["profile" => "", "type" => VARIABLETYPE_FLOAT];
        }
    }
    

    private function Registers()
    {
        return [
            ["address" => 35100, "name" => "RTC",      "type" => "U16", "unit" => "N/A", "scale" => 1,   "quantity" => 1, "readOnly" => true, "action" => false, "remark" => "Hbyte-year/Lbyte-month: 13-99/1-12", "category" => "Wechselrichter"],
            ["address" => 35103, "name" => "Vpv1",     "type" => "U16", "unit" => "V",   "scale" => 10,  "quantity" => 1, "readOnly" => true, "action" => false, "remark" => "PV1 voltage",                        "category" => "Wechselrichter"],
            ["address" => 35104, "name" => "Ipv1",     "type" => "U16", "unit" => "A",   "scale" => 10,  "quantity" => 1, "readOnly" => true, "action" => false, "remark" => "PV1 current",                        "category" => "Wechselrichter"],
            ["address" => 35191, "name" => "PV_E_Total",     "type" => "U32", "unit" => "kWh",   "scale" => 10,  "quantity" => 2, "readOnly" => true, "action" => false, "remark" => "PV1 Power",                          "category" => "Wechselrichter"],
            ["address" => 35107, "name" => "Vpv2",     "type" => "U16", "unit" => "V",   "scale" => 10,  "quantity" => 1, "readOnly" => true, "action" => false, "remark" => "PV2 voltage",                        "category" => "Wechselrichter"],
            ["address" => 35121, "name" => "Vgrid_R",  "type" => "U16", "unit" => "V",   "scale" => 10,  "quantity" => 1, "readOnly" => true, "action" => false, "remark" => "R phase Grid voltage",               "category" => "Smartmeter"],
            ["address" => 35122, "name" => "Igrid_R",  "type" => "U16", "unit" => "A",   "scale" => 10,  "quantity" => 1, "readOnly" => true, "action" => false, "remark" => "R phase Grid current",               "category" => "Smartmeter"],
            ["address" => 35123, "name" => "Fgrid_R",  "type" => "U16", "unit" => "Hz",  "scale" => 100, "quantity" => 1, "readOnly" => true, "action" => false, "remark" => "R phase Grid Frequency",             "category" => "Smartmeter"],
            ["address" => 47908, "name" => "SOC",      "type" => "U16", "unit" => "%",   "scale" => 1,   "quantity" => 1, "readOnly" => true, "action" => false, "remark" => "Battery state of charge",            "category" => "Batterie"],
            ["address" => 35201, "name" => "BatteryV", "type" => "U16", "unit" => "V",   "scale" => 10,  "quantity" => 1, "readOnly" => true, "action" => false, "remark" => "Battery voltage",                    "category" => "Batterie"],
            ["address" => 35202, "name" => "BatteryI", "type" => "U16", "unit" => "A",   "scale" => 10,  "quantity" => 1, "readOnly" => true, "action" => false, "remark" => "Battery current",                    "category" => "Batterie"],
            ["address" => 36025, "name" => "EVU_TOTAL",   "type" => "S32", "unit" => "W",  "scale" => 1,  "quantity" => 2, "readOnly" => true, "action" => false, "remark" => "Temperatur Inverter",                "category" => "Wechselrichter"]
        ];
    }
    

    public function RequestRead()
    {
        foreach ($this->Registers() as $register) {
            $value = $this->ReadRegister($register['address'], $register['type'], $register['scale']);
            SetValue($this->GetIDForIdent($register['name']), $value);
        }
    }
    
    private function ReadRegister(int $address, string $type, float $scale)
    {
        $quantity = ($type === "U32" || $type === "S32") ? 2 : 1;
    
        $response = $this->SendDataToParent(json_encode([
            "DataID"   => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
            "Function" => 3,
            "Address"  => $address,
            "Quantity" => $quantity,
            "Data"     => ""
        ]));
    
        if ($response === false || strlen($response) < (2 * $quantity + 2)) {
            $this->SendDebug("Error", "No or incomplete response for Register $address", 0);
            return 0;
        }
    
        $this->SendDebug("Raw Response for Register $address", bin2hex($response), 0);
        $data = unpack("n*", substr($response, 2));
    
        $value = 0;
        if ($type === "U16") {
            $value = $data[1];
        } elseif ($type === "S16") {
            $value = unpack("s", pack("n", $data[1]))[1]; // Umwandlung in SIGNED
        } elseif ($type === "U32") {
            $value = ($data[1] << 16) | $data[2];
        } elseif ($type === "S32") {
            $combined = ($data[1] << 16) | $data[2];
            $value = unpack("l", pack("N", $combined))[1]; // SIGNED 32-Bit
        }
    
        return $value / $scale;
    }    
    
}