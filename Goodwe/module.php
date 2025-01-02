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
        
    }

    public function RequestRead()
    {
        // Register für Spannung (Volt)
        $responseVolt = $this->SendDataToParent(json_encode([
            "DataID" => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
            "Function" => 3,
            "Address" => 35107,
            "Quantity" => 2
        ]));
    
        $this->SendDebug("Volt Request", json_encode([
            "DataID" => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
            "Function" => 3,
            "Address" => 35107,
            "Quantity" => 2
        ]), 0);
    
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
    
        // Analog für weitere Register
        $this->ProcessRegister("Ampere", 35104, 2, 1000);
        $this->ProcessRegister("Watt", 35301, 2, 10);
        $this->ProcessRegister("kWh", 35191, 2, 10);
    }
    
    private function ProcessRegister(string $name, int $address, int $quantity, float $scale)
    {
        $response = $this->SendDataToParent(json_encode([
            "DataID" => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
            "Function" => 3,
            "Address" => $address,
            "Quantity" => $quantity
        ]));
    
        $this->SendDebug("$name Request", json_encode([
            "DataID" => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
            "Function" => 3,
            "Address" => $address,
            "Quantity" => $quantity
        ]), 0);
    
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
    
        if(IPS_GetProperty(IPS_GetInstance($this->InstanceID)['ConnectionID'], "SwapWords")) {
            SetValue($this->GetIDForIdent("Volt"), ($Volt[1] + ($Volt[2] << 16))/10);
            SetValue($this->GetIDForIdent("Ampere"), ($Ampere[1] + ($Ampere[2] << 16))/1000);
            SetValue($this->GetIDForIdent("Watt"), ($Watt[1] + ($Watt[2] << 16))/10);
            SetValue($this->GetIDForIdent("kWh"), ($KWh[1] + ($KWh[2] << 16))/10);
        } else {
            SetValue($this->GetIDForIdent("Volt"), ($Volt[2] + ($Volt[1] << 16))/10);
            SetValue($this->GetIDForIdent("Ampere"), ($Ampere[2] + ($Ampere[1] << 16))/1000);
            SetValue($this->GetIDForIdent("Watt"), ($Watt[2] + ($Watt[1] << 16))/10);
            SetValue($this->GetIDForIdent("kWh"), ($KWh[2] + ($KWh[1] << 16))/10);
        } 
    }
}