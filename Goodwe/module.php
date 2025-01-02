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
    
        $this->SendDebug("Volt Raw Response", bin2hex($responseVolt), 0);
    
        if ($responseVolt === false || strlen($responseVolt) < 5) {
            $this->SendDebug("Volt Error", "No or incomplete response received", 0);
        } else {
            $dataVolt = unpack("n2", substr($responseVolt, 2));
            $volt = ($dataVolt[1] << 16 | $dataVolt[2]) / 10; // 32-Bit kombinieren und skalieren
            $this->SendDebug("Volt Parsed", $volt, 0);
            SetValue($this->GetIDForIdent("Volt"), $volt);
        }
    
        // Register für Strom (Ampere)
        $responseAmpere = $this->SendDataToParent(json_encode([
            "DataID" => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
            "Function" => 3,
            "Address" => 35104,
            "Quantity" => 2
        ]));
    
        $this->SendDebug("Ampere Raw Response", bin2hex($responseAmpere), 0);
    
        if ($responseAmpere === false || strlen($responseAmpere) < 5) {
            $this->SendDebug("Ampere Error", "No or incomplete response received", 0);
        } else {
            $dataAmpere = unpack("n2", substr($responseAmpere, 2));
            $ampere = ($dataAmpere[1] << 16 | $dataAmpere[2]) / 1000; // 32-Bit kombinieren und skalieren
            $this->SendDebug("Ampere Parsed", $ampere, 0);
            SetValue($this->GetIDForIdent("Ampere"), $ampere);
        }
    
        // Register für Leistung (Watt)
        $responseWatt = $this->SendDataToParent(json_encode([
            "DataID" => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
            "Function" => 3,
            "Address" => 35301,
            "Quantity" => 2
        ]));
    
        $this->SendDebug("Watt Raw Response", bin2hex($responseWatt), 0);
    
        if ($responseWatt === false || strlen($responseWatt) < 5) {
            $this->SendDebug("Watt Error", "No or incomplete response received", 0);
        } else {
            $dataWatt = unpack("n2", substr($responseWatt, 2));
            $watt = ($dataWatt[1] << 16 | $dataWatt[2]) / 10; // 32-Bit kombinieren und skalieren
            $this->SendDebug("Watt Parsed", $watt, 0);
            SetValue($this->GetIDForIdent("Watt"), $watt);
        }
    
        // Register für Energie (kWh)
        $responseKWh = $this->SendDataToParent(json_encode([
            "DataID" => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
            "Function" => 3,
            "Address" => 35191,
            "Quantity" => 2
        ]));
    
        $this->SendDebug("KWh Raw Response", bin2hex($responseKWh), 0);
    
        if ($responseKWh === false || strlen($responseKWh) < 5) {
            $this->SendDebug("KWh Error", "No or incomplete response received", 0);
        } else {
            $dataKWh = unpack("n2", substr($responseKWh, 2));
            $kwh = ($dataKWh[1] << 16 | $dataKWh[2]) / 10; // 32-Bit kombinieren und skalieren
            $this->SendDebug("KWh Parsed", $kwh, 0);
            SetValue($this->GetIDForIdent("kWh"), $kwh);
        
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