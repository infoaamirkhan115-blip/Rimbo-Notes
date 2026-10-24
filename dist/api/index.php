<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/backend/bootstrap.php';
require dirname(__DIR__,2).'/backend/validation.php';
$route=$_GET['route']??'session';
$method=$_SERVER['REQUEST_METHOD'];
if($method!=='GET')requireCsrf();
if($route==='session'&&$method==='GET') {
    $user=null;
    if(isset($_SESSION['user_id'])) {
        $q=db()->prepare('SELECT id,name,email FROM users WHERE id=?');$q->execute([$_SESSION['user_id']]);$user=$q->fetch()?:null;
        if(!$user)unset($_SESSION['user_id']);
    }
    reply(200,['user'=>$user,'csrf'=>$_SESSION['csrf'],'registration_open'=>configBool('registration_open',true)]);
}
if(in_array($route,['login','register'],true)&&$method==='POST') {
    $b=body();$email=strtolower(trim(textValue($b,'email',254)));$password=textValue($b,'password',1024);
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)||!preg_match('/^[\x20-\x7E]+$/D',$email)) invalid('Enter a valid email address.');
    throttle($email);
    if($route==='register') {
        if(!configBool('registration_open',true))reply(403,['error'=>'Registration is closed.']);
        $name=trim(textValue($b,'name',100));
        if($name===''||strlen($password)<12||strlen($password)>72)invalid('Use a name and a password between 12 and 72 bytes.');
        $pdo=db();$pdo->beginTransaction();
        try {
            $q=$pdo->prepare('INSERT INTO users (name,email,password_hash) VALUES (?,?,?)');
            $q->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
            $id=(int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO workspaces (user_id,settings) VALUES (?,?)')->execute([$id,json_encode(defaultSettings($name),JSON_THROW_ON_ERROR)]);
            $pdo->commit();
        } catch(PDOException $e) {
            $pdo->rollBack();
            if($e->getCode()==='23000')reply(409,['error'=>'An account with this email already exists. Sign in instead.']);
            throw $e;
        }
        $user=['id'=>$id,'name'=>$name,'email'=>$email];
    } else {
        $q=db()->prepare('SELECT id,name,email,password_hash FROM users WHERE email=?');$q->execute([$email]);$user=$q->fetch();
        $hash=$user['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        if(!password_verify($password,$hash)||!$user)reply(401,['error'=>'Email or password is incorrect.']);
        if(password_needs_rehash($hash,PASSWORD_DEFAULT))db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$user['id']]);
        unset($user['password_hash']);
    }
    signIn($user);reply($route==='register'?201:200,['user'=>$user,'csrf'=>$_SESSION['csrf']]);
}
if($route==='logout'&&$method==='POST') {
    $_SESSION=[];session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));
    reply(200,['ok'=>true,'csrf'=>$_SESSION['csrf']]);
}
$userId=requireUser();
if($route==='workspace'&&$method==='GET') {
    $pdo=db();$pdo->beginTransaction();
    // Shared lock gives a coherent settings/ideas snapshot while a save runs.
    $q=$pdo->prepare('SELECT revision,settings FROM workspaces WHERE user_id=? LOCK IN SHARE MODE');$q->execute([$userId]);$w=$q->fetch();
    $q=$pdo->prepare('SELECT payload FROM ideas WHERE user_id=? ORDER BY position');$q->execute([$userId]);
    $ideas=array_map(fn($row)=>json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR),$q->fetchAll());
    $pdo->commit();reply(200,['revision'=>(int)$w['revision'],'state'=>['ideas'=>$ideas,'settings'=>json_decode($w['settings'],true,64,JSON_THROW_ON_ERROR)]]);
}
if($route==='workspace'&&$method==='PUT') {
    $b=body();if(!isset($b['revision'])||!is_int($b['revision'])||$b['revision']<0||!is_array($b['state']??null))invalid('Provide a workspace and its integer revision.');
    $clean=cleanWorkspace($b['state']);
    $pdo=db();$pdo->beginTransaction();
    $q=$pdo->prepare('SELECT revision FROM workspaces WHERE user_id=? FOR UPDATE');$q->execute([$userId]);$current=(int)$q->fetchColumn();
    if($current!==$b['revision']){$pdo->rollBack();reply(409,['error'=>'This workspace changed in another tab or device. Export your pending changes, then reload the latest version.','revision'=>$current]);}
    $pdo->prepare('DELETE FROM ideas WHERE user_id=?')->execute([$userId]);
    $q=$pdo->prepare('INSERT INTO ideas (user_id,idea_id,position,payload) VALUES (?,?,?,?)');
    foreach($clean['ideas'] as $position=>$idea)$q->execute([$userId,$idea['id'],$position,json_encode($idea,JSON_THROW_ON_ERROR)]);
    $pdo->prepare('UPDATE workspaces SET settings=?,revision=revision+1 WHERE user_id=?')->execute([json_encode($clean['settings'],JSON_THROW_ON_ERROR),$userId]);
    $pdo->commit();reply(200,['revision'=>$current+1,'saved'=>true]);
}
reply(404,['error'=>'Unknown endpoint or HTTP method.']);
