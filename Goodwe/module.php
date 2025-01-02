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
        $responseVolt = $this->SendDataToParent(pack("C2n2", 1, 3, 35107, 2)); // Unit ID = 1, Function Code = 3
        $this->SendDebug("Volt Raw Request", bin2hex(pack("C2n2", 1, 3, 35107, 2)), 0);
    
        if ($responseVolt === false) {
            $this->SendDebug("Volt Error", "No response received", 0);
            return;
        }
    
        $this->SendDebug("Volt Raw Response", bin2hex($responseVolt), 0);
    
        if (strlen($responseVolt) < 5) {
            $this->SendDebug("Volt Error", "Incomplete response received", 0);
            return;
        }
    
        $dataVolt = unpack("n2", substr($responseVolt, 2));
        $volt = ($dataVolt[1] << 16 | $dataVolt[2]) / 10; // 32-Bit kombinieren und skalieren
        $this->SendDebug("Volt Parsed", $volt, 0);
        SetValue($this->GetIDForIdent("Volt"), $volt);
    
        // Wiederhole für weitere Register (Ampere, Watt, kWh)
        $this->ProcessRegister("Ampere", 35104, 2, 1000);
        $this->ProcessRegister("Watt", 35301, 2, 10);
        $this->ProcessRegister("kWh", 35191, 2, 10);
    }
    
    private function ProcessRegister(string $name, int $address, int $quantity, float $scale)
    {
        $request = pack("C2n2", 1, 3, $address, $quantity);
        $response = $this->SendDataToParent($request);
    
        $this->SendDebug("$name Raw Request", bin2hex($request), 0);
    
        if ($response === false) {
            $this->SendDebug("$name Error", "No response received", 0);
            return;
        }
    
        $this->SendDebug("$name Raw Response", bin2hex($response), 0);
    
        if (strlen($response) < 5) {
            $this->SendDebug("$name Error", "Incomplete response received", 0);
            return;
        }
    
        $data = unpack("n2", substr($response, 2));
        $value = ($data[1] << 16 | $data[2]) / $scale; // 32-Bit kombinieren und skalieren
        $this->SendDebug("$name Parsed", $value, 0);
        SetValue($this->GetIDForIdent($name), $value);
    }
    
}