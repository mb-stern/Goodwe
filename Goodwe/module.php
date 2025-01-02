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

    public function ApplyChanges() {
        //Never delete this line!
        parent::ApplyChanges();
        
        $this->RegisterVariableFloat("Volt", "Volt", "Volt.230", 1);
        $this->RegisterVariableFloat("Ampere", "Ampere", "Ampere.16", 2);
        $this->RegisterVariableFloat("Watt", "Watt", "Watt.14490", 3);
        $this->RegisterVariableFloat("kWh", "Total kWh", "Electricity", 4);
        
        $this->SetTimerInterval("Poller", $this->ReadPropertyInteger("Poller"));

        $this->RegisterVariableFloat("Volt", "Volt", "~Volt", 1);
        
    }

    public function RequestRead()
    {
        // Spannung (Volt) abfragen
        $requestVolt = pack("C2n2", 1, 3, 35107, 2); // Unit ID = 1, Function Code = 3, Address = 35107, Quantity = 2
        $responseVolt = $this->SendDataToParent(json_encode([
            "DataID"  => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
            "Payload" => bin2hex($requestVolt) // Binärdaten als Hex
        ]));
    
        $this->SendDebug("Volt Request", bin2hex($requestVolt), 0);
    
        if ($responseVolt === false) {
            $this->SendDebug("Volt Error", "No response received", 0);
            return;
        }
    
        $this->SendDebug("Volt Raw Response", $responseVolt, 0);
    
        $responseDecoded = json_decode($responseVolt, true);
        if (isset($responseDecoded['Payload'])) {
            $responsePayload = hex2bin($responseDecoded['Payload']); // Hex zurück in Binärdaten umwandeln
            if (strlen($responsePayload) < 5) {
                $this->SendDebug("Volt Error", "Incomplete response received", 0);
                return;
            }
    
            $dataVolt = unpack("n2", substr($responsePayload, 2));
            $volt = ($dataVolt[1] << 16 | $dataVolt[2]) / 10; // 32-Bit kombinieren und skalieren
            $this->SendDebug("Volt Parsed", $volt, 0);
            SetValue($this->GetIDForIdent("Volt"), $volt);
        } else {
            $this->SendDebug("Volt Error", "Invalid response format", 0);
        }
    
        // Wiederhole für andere Register
        $this->ProcessRegister("Ampere", 35104, 2, 1000);
        $this->ProcessRegister("Watt", 35301, 2, 10);
        $this->ProcessRegister("kWh", 35191, 2, 10);
    }
    
    private function ProcessRegister(string $name, int $address, int $quantity, float $scale)
    {
        $request = pack("C2n2", 1, 3, $address, $quantity);
        $response = $this->SendDataToParent(json_encode([
            "DataID"  => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
            "Payload" => bin2hex($request)
        ]));
    
        $this->SendDebug("$name Request", bin2hex($request), 0);
    
        if ($response === false) {
            $this->SendDebug("$name Error", "No response received", 0);
            return;
        }
    
        $this->SendDebug("$name Raw Response", $response, 0);
    
        $responseDecoded = json_decode($response, true);
        if (isset($responseDecoded['Payload'])) {
            $responsePayload = hex2bin($responseDecoded['Payload']);
            if (strlen($responsePayload) < 5) {
                $this->SendDebug("$name Error", "Incomplete response received", 0);
                return;
            }
    
            $data = unpack("n2", substr($responsePayload, 2));
            $value = ($data[1] << 16 | $data[2]) / $scale; // 32-Bit kombinieren und skalieren
            $this->SendDebug("$name Parsed", $value, 0);
            SetValue($this->GetIDForIdent($name), $value);
        } else {
            $this->SendDebug("$name Error", "Invalid response format", 0);
        }
    }
    
}