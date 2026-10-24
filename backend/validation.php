<?php
declare(strict_types=1);
function invalid(string $message): never { reply(422, ['error'=>$message]); }
function textValue(array $data, string $key, int $max, string $default=''): string {
    $v=$data[$key]??$default;
    if (!is_string($v) || strlen($v)>$max || preg_match('//u', $v)!==1) invalid('Invalid or oversized field: '.$key);
    return $v;
}
function identifier(mixed $value): string {
    if (!is_string($value)||!preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',$value)) invalid('Invalid idea or attachment ID.');
    return $value;
}
function dataUrl(mixed $v, bool $imageOnly=false, bool $audioOnly=false): string {
    if (!is_string($v)||strlen($v)>3000000) invalid('Attachment exceeds the 2 MB limit.');
    if (!preg_match('~^data:([a-z0-9.+/-]+)(?:;codecs=[a-z0-9,.-]+)?;base64,([A-Za-z0-9+/=]+)$~iD',$v,$m)) invalid('Invalid attachment format.');
    $type=strtolower($m[1]);
    $images=['image/jpeg','image/png','image/gif','image/webp'];
    $audios=['audio/webm','audio/ogg','audio/mp4','audio/mpeg','audio/wav'];
    $allow=$imageOnly?$images:($audioOnly?$audios:array_merge($images,$audios,['application/pdf','text/plain','application/octet-stream']));
    if (!in_array($type,$allow,true)) invalid('Unsupported attachment type. Use PNG, JPG, WebP, GIF, PDF, text or audio.');
    $bytes=base64_decode($m[2],true);
    if ($bytes===false||strlen($bytes)>2097152) invalid('Attachment exceeds the 2 MB limit.');
    return $v;
}
function cleanWorkspace(array $input): array {
    if (!isset($input['ideas'],$input['settings']) || !is_array($input['ideas']) || !array_is_list($input['ideas']) || count($input['ideas'])>500 || !is_array($input['settings'])) invalid('A workspace must have settings and at most 500 ideas.');
    $ideas=[]; $seen=[];
    foreach($input['ideas'] as $raw) {
        if(!is_array($raw)) invalid('Invalid idea.');
        $id=identifier($raw['id']??null);
        if(isset($seen[$id])) invalid('Duplicate idea ID.');
        $seen[$id]=true;
        $i=['id'=>$id];
        foreach(['title'=>720,'notes'=>200000,'why'=>20000,'trigger'=>20000,'next'=>2000,'tags'=>2000,'context'=>20000] as $key=>$limit) $i[$key]=textValue($raw,$key,$limit);
        if(trim($i['title'])==='') invalid('Every idea needs a title.');
        foreach(['category'=>['Unsorted','Business','Design','Personal'],'stage'=>['Captured','Exploring','Trying','Completed'],'source'=>['text','voice','photo','link'],'repeat'=>['none','daily','weekly','monthly']] as $key=>$allowed) {
            $i[$key]=$raw[$key]??$allowed[0];
            if(!in_array($i[$key],$allowed,true)) invalid('Invalid '.$key);
        }
        foreach(['deleted','archived','favorite','nextDone','notified'] as $key) {
            if(isset($raw[$key])&&!is_bool($raw[$key])) invalid('Invalid '.$key);
            $i[$key]=$raw[$key]??false;
        }
        foreach(['created','updated','revisited','deletedAt'] as $key) if(isset($raw[$key])) {
            if(!is_numeric($raw[$key])||$raw[$key]<0||$raw[$key]>32503680000000) invalid('Invalid timestamp.');
            $i[$key]=(int)$raw[$key];
        }
        $i['created']??=(int)(microtime(true)*1000); $i['updated']??=$i['created'];
        if(!empty($raw['reminder'])) {
            if(!is_string($raw['reminder'])||strlen($raw['reminder'])>40||strtotime($raw['reminder'])===false) invalid('Invalid reminder date.');
            $i['reminder']=$raw['reminder'];
        }
        $i['links']=$raw['links']??[];
        if(!is_array($i['links'])||!array_is_list($i['links'])||count($i['links'])>500) invalid('Invalid connections.');
        $i['links']=array_values(array_unique(array_map('identifier',$i['links'])));
        $i['attachments']=[];
        if(!is_array($raw['attachments']??[])||count($raw['attachments']??[])>20) invalid('Maximum 20 attachments per idea.');
        foreach($raw['attachments']??[] as $a) {
            if(!is_array($a)) invalid('Invalid attachment.');
            $data=dataUrl($a['data']??null);
            preg_match('~^data:([^;,]+)~',$data,$m);
            $i['attachments'][]=['id'=>identifier($a['id']??null),'name'=>textValue($a,'name',255),'type'=>$m[1],'data'=>$data];
        }
        if(!empty($raw['cover'])) {
            if(str_starts_with($raw['cover'],'data:')) $i['cover']=dataUrl($raw['cover'],true);
            elseif(is_string($raw['cover'])&&preg_match('~^https://images\.unsplash\.com/[a-zA-Z0-9_/?=&%.+-]+$~D',$raw['cover'])) $i['cover']=$raw['cover'];
            else invalid('Invalid cover image URL.');
        }
        if(!empty($raw['audio'])) $i['audio']=dataUrl($raw['audio'],false,true);
        $i['history']=[];
        if(!is_array($raw['history']??[])||count($raw['history']??[])>500) invalid('Invalid idea history.');
        foreach($raw['history']??[] as $h) {
            if(!is_array($h)||!isset($h['at'])||!is_numeric($h['at'])) invalid('Invalid history entry.');
            $i['history'][]=['at'=>(int)$h['at'],'text'=>textValue($h,'text',20000)];
        }
        $ideas[]=$i;
    }
    foreach($ideas as &$i) $i['links']=array_values(array_filter($i['links'],fn($id)=>isset($seen[$id])&&$id!==$i['id']));
    unset($i);
    $s=$input['settings']; $settings=defaultSettings(textValue($s,'name',400,'Your space'));
    foreach(['review','quiet'] as $k) { if(isset($s[$k])&&!is_bool($s[$k])) invalid('Invalid preference.'); $settings[$k]=$s[$k]??true; }
    foreach(['language'=>['en-IN','hi-IN'],'day'=>['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday']] as $k=>$allowed) {
        if(isset($s[$k])&&!in_array($s[$k],$allowed,true)) invalid('Invalid preference: '.$k);
        $settings[$k]=$s[$k]??$settings[$k];
    }
    $settings['time']=textValue($s,'time',5,'18:00');
    if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$settings['time'])) invalid('Invalid review time.');
    if(isset($s['lastReview']))$settings['lastReview']=textValue($s,'lastReview',100);
    return ['ideas'=>$ideas,'settings'=>$settings];
}
