<?php
declare(strict_types=1);

class PoolheizungKachel extends IPSModule
{
    private const SOURCES = ['PrimaryPumpID','SecondaryPumpID','StartCommandID','StopCommandID','PrimaryFlowID','PrimaryReturnID','SecondaryFlowID','SecondaryReturnID','DayNightID'];
    private const REQUIRED = ['PrimaryPumpID','SecondaryPumpID','StartCommandID','StopCommandID','PrimaryFlowID','PrimaryReturnID','SecondaryFlowID','SecondaryReturnID'];

    public function Create()
    {
        parent::Create();
        $defaults=['PrimaryPumpID'=>16037,'SecondaryPumpID'=>45308,'StartCommandID'=>20962,'StopCommandID'=>15198,'PrimaryFlowID'=>17966,'PrimaryReturnID'=>38717,'SecondaryFlowID'=>52834,'SecondaryReturnID'=>21448,'DayNightID'=>33580];
        foreach($defaults as $name=>$id) $this->RegisterPropertyInteger($name,$id);
        $this->RegisterAttributeString('RegisteredSources','[]');
        $this->RegisterVariableBoolean('DataValid','Daten gültig','~Switch',10);
        $this->RegisterVariableInteger('LastUpdate','Letzte Aktualisierung','~UnixTimestamp',20);
        $this->RegisterVariableString('LastError','Fehlermeldung','',30);
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $old=json_decode($this->ReadAttributeString('RegisteredSources'),true);
        if(is_array($old)) foreach($old as $id){$this->UnregisterMessage((int)$id,VM_UPDATE);$this->UnregisterReference((int)$id);}
        $registered=[];$missing=[];
        foreach(self::SOURCES as $property){
            $id=$this->ReadPropertyInteger($property);
            if($id>0 && IPS_VariableExists($id)){$this->RegisterReference($id);$this->RegisterMessage($id,VM_UPDATE);$registered[]=$id;}
            elseif(in_array($property,self::REQUIRED,true)) $missing[]=$property;
        }
        $this->WriteAttributeString('RegisteredSources',json_encode($registered));
        $valid=$missing===[];
        $this->SetValue('DataValid',$valid);$this->SetValue('LastUpdate',time());
        $this->SetValue('LastError',$valid?'':'Erforderliche Quellvariablen fehlen');
        $this->SetStatus($valid?102:201);$this->PushTile();
    }

    public function MessageSink($TimeStamp,$SenderID,$Message,$Data)
    {
        if($Message===VM_UPDATE){$this->SetValue('LastUpdate',time());$this->PushTile();}
    }

    public function RequestAction($Ident,$Value)
    {
        if(!in_array($Ident,['Start','Stop'],true)) throw new InvalidArgumentException('Unbekannte Aktion');
        $id=$this->ReadPropertyInteger($Ident==='Start'?'StartCommandID':'StopCommandID');
        if($id<=0 || !IPS_VariableExists($id)) throw new RuntimeException('Befehlsvariable fehlt');
        try{
            RequestAction($id,true);
            try{IPS_Sleep(300);}finally{RequestAction($id,false);}
            $this->SetValue('LastError','');$this->SetValue('DataValid',true);$this->SetValue('LastUpdate',time());$this->SetStatus(102);
        }catch(Throwable $e){
            $this->SetValue('LastError',$e->getMessage());$this->SetStatus(202);$this->PushTile();throw $e;
        }
        IPS_Sleep(150);$this->PushTile();
    }

    public function GetVisualizationTile()
    {
        return str_replace(
            ['__INITIAL_STATE__','__SOLAR_ASSET__','__POOL_ASSET__'],
            [$this->StateJSON(),$this->AssetData('solar.png'),$this->AssetData('Pool.png')],
            file_get_contents(__DIR__.'/module.html')
        );
    }

    private function Value(string $property,$fallback=null)
    {
        $id=$this->ReadPropertyInteger($property);
        return $id>0 && IPS_VariableExists($id)?GetValue($id):$fallback;
    }

    private function StateJSON(): string
    {
        $q1=(bool)$this->Value('PrimaryPumpID',false);$q2=(bool)$this->Value('SecondaryPumpID',false);
        return (string)json_encode([
            'valid'=>(bool)$this->GetValue('DataValid'),'primaryPump'=>$q1,'secondaryPump'=>$q2,
            'primaryFlow'=>(float)$this->Value('PrimaryFlowID',0),'primaryReturn'=>(float)$this->Value('PrimaryReturnID',0),
            'secondaryFlow'=>(float)$this->Value('SecondaryFlowID',0),'secondaryReturn'=>(float)$this->Value('SecondaryReturnID',0),
            'isDay'=>(bool)$this->Value('DayNightID',true),'running'=>$q1||$q2,
            'updated'=>(int)$this->GetValue('LastUpdate'),'error'=>(string)$this->GetValue('LastError')
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP);
    }

    private function PushTile(): void {$this->UpdateVisualizationValue($this->StateJSON());}

    private function AssetData(string $name): string
    {
        $path=__DIR__.'/assets/'.$name;
        return is_file($path)?'data:image/png;base64,'.base64_encode((string)file_get_contents($path)):'';
    }
}
