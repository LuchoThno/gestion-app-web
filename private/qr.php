<?php
// Código QR (ISO/IEC 18004) generado en el servidor, sin bibliotecas externas ni JavaScript.
// Modo byte, corrección de errores nivel M, versiones 1 a 20 (hasta 669 bytes). Se usa para enrolar la verificación en dos pasos.

/** Matriz de módulos (true = oscuro) para el texto. */
function qrMatrix(string $data): array {
    // [ecc por bloque, bloques grupo 1, datos grupo 1, bloques grupo 2, datos grupo 2] para nivel M.
    static $ec=[1=>[10,1,16,0,0],[16,1,28,0,0],[26,1,44,0,0],[18,2,32,0,0],[24,2,43,0,0],[16,4,27,0,0],[18,4,31,0,0],[22,2,38,2,39],[22,3,36,2,37],[26,4,43,1,44],
        [30,1,50,4,51],[22,6,36,2,37],[22,8,37,1,38],[24,4,40,5,41],[24,5,41,5,42],[28,7,45,3,46],[28,10,46,1,47],[26,9,43,4,44],[26,3,44,11,45],[26,3,41,13,42]];
    static $align=[2=>[6,18],[6,22],[6,26],[6,30],[6,34],[6,22,38],[6,24,42],[6,26,46],[6,28,50],[6,30,54],[6,32,58],[6,34,62],[6,26,46,66],[6,26,48,70],[6,26,50,74],[6,30,54,78],[6,30,56,82],[6,30,58,86],[6,34,62,90]];
    $len=strlen($data); $ver=0;
    foreach($ec as $v=>[$e,$b1,$d1,$b2,$d2]){ $cap=$b1*$d1+$b2*$d2; $need=4+($v<10?8:16)+8*$len; if($need<=$cap*8){ $ver=$v; break; } }
    if(!$ver) throw new RuntimeException('Texto demasiado largo para el código QR.');
    [$e,$b1,$d1,$b2,$d2]=$ec[$ver]; $cap=$b1*$d1+$b2*$d2;
    // Flujo de bits: modo byte (0100), largo, datos, terminador y relleno.
    $bits='0100'.str_pad(decbin($len),$ver<10?8:16,'0',STR_PAD_LEFT);
    for($i=0;$i<$len;$i++) $bits.=str_pad(decbin(ord($data[$i])),8,'0',STR_PAD_LEFT);
    $bits.=str_repeat('0',min(4,$cap*8-strlen($bits)));
    if(strlen($bits)%8) $bits.=str_repeat('0',8-strlen($bits)%8);
    $cw=array_map('bindec',str_split($bits,8));
    for($p=0;count($cw)<$cap;$p^=1) $cw[]=$p?0x11:0xEC;
    // Reed-Solomon sobre GF(256), polinomio 0x11D.
    static $exp=[],$log=[];
    if(!$exp){ $x=1; for($i=0;$i<255;$i++){ $exp[$i]=$x; $log[$x]=$i; $x<<=1; if($x&0x100) $x^=0x11D; } for($i=255;$i<512;$i++) $exp[$i]=$exp[$i-255]; }
    $gen=[1]; for($i=0;$i<$e;$i++){ $n=array_fill(0,count($gen)+1,0); foreach($gen as $j=>$g){ $n[$j]^=$g; $n[$j+1]^=$g?$exp[$log[$g]+$i]:0; } $gen=$n; }
    $blocks=[]; $eccs=[]; $pos=0;
    foreach([[$b1,$d1],[$b2,$d2]] as [$nb,$nd]) for($b=0;$b<$nb;$b++){
        $blk=array_slice($cw,$pos,$nd); $pos+=$nd; $blocks[]=$blk;
        $r=array_merge($blk,array_fill(0,$e,0));
        for($i=0;$i<$nd;$i++){ $c=$r[$i]; if($c) for($j=1;$j<=$e;$j++) $r[$i+$j]^=$gen[$j]?$exp[$log[$gen[$j]]+$log[$c]]:0; }
        $eccs[]=array_slice($r,$nd);
    }
    $final=[]; $maxd=max($d1,$d2);
    for($i=0;$i<$maxd;$i++) foreach($blocks as $blk) if(isset($blk[$i])) $final[]=$blk[$i];
    for($i=0;$i<$e;$i++) foreach($eccs as $ecb) $final[]=$ecb[$i];
    $stream=''; foreach($final as $c) $stream.=str_pad(decbin($c),8,'0',STR_PAD_LEFT);
    // Patrones fijos.
    $size=17+4*$ver; $m=array_fill(0,$size,array_fill(0,$size,null));
    $finder=function(int $r,int $c) use(&$m,$size){ for($i=-1;$i<=7;$i++) for($j=-1;$j<=7;$j++){ $y=$r+$i; $x=$c+$j; if($y<0||$x<0||$y>=$size||$x>=$size) continue; $m[$y][$x]=$i>=0&&$i<=6&&$j>=0&&$j<=6&&($i===0||$i===6||$j===0||$j===6||($i>=2&&$i<=4&&$j>=2&&$j<=4)); } };
    $finder(0,0); $finder(0,$size-7); $finder($size-7,0);
    for($i=8;$i<$size-8;$i++){ $m[6][$i]=$i%2===0; $m[$i][6]=$i%2===0; }
    $al=$align[$ver]??[]; $last=count($al)-1;
    foreach($al as $iy=>$ay) foreach($al as $ix=>$ax){
        if(($iy===0&&$ix===0)||($iy===0&&$ix===$last)||($iy===$last&&$ix===0)) continue; // se superpone a un buscador
        for($i=-2;$i<=2;$i++) for($j=-2;$j<=2;$j++) $m[$ay+$i][$ax+$j]=max(abs($i),abs($j))!==1;
    }
    // Reserva formato y versión.
    for($i=0;$i<9;$i++){ $m[8][$i]??=false; $m[$i][8]??=false; }
    for($i=0;$i<8;$i++){ $m[8][$size-1-$i]??=false; $m[$size-1-$i][8]??=false; }
    $m[$size-8][8]=true;
    if($ver>=7) for($i=0;$i<6;$i++) for($j=0;$j<3;$j++){ $m[$i][$size-11+$j]=false; $m[$size-11+$j][$i]=false; }
    $fixed=array_map(fn($row)=>array_map(fn($v)=>$v!==null,$row),$m);
    // Datos en zigzag.
    $k=0; $n=strlen($stream); $up=true;
    for($x=$size-1;$x>0;$x-=2){
        if($x===6) $x--;
        for($t=0;$t<$size;$t++){ $y=$up?$size-1-$t:$t;
            for($d=0;$d<2;$d++){ $xx=$x-$d; if($fixed[$y][$xx]) continue; $m[$y][$xx]=$k<$n&&$stream[$k]==='1'; $k++; } }
        $up=!$up;
    }
    // Elige la máscara con menor penalización.
    $masks=[fn($y,$x)=>($y+$x)%2===0, fn($y,$x)=>$y%2===0, fn($y,$x)=>$x%3===0, fn($y,$x)=>($y+$x)%3===0, fn($y,$x)=>(intdiv($y,2)+intdiv($x,3))%2===0,
        fn($y,$x)=>($y*$x)%2+($y*$x)%3===0, fn($y,$x)=>(($y*$x)%2+($y*$x)%3)%2===0, fn($y,$x)=>(($y+$x)%2+($y*$x)%3)%2===0];
    $best=null; $bestScore=PHP_INT_MAX;
    foreach($masks as $mi=>$fn){
        $t=$m;
        for($y=0;$y<$size;$y++) for($x=0;$x<$size;$x++) if(!$fixed[$y][$x] && $fn($y,$x)) $t[$y][$x]=!$t[$y][$x];
        qrPlaceInfo($t,$size,$ver,$mi);
        $s=qrPenalty($t,$size); if($s<$bestScore){ $bestScore=$s; $best=$t; }
    }
    return $best;
}
function qrPlaceInfo(array &$m, int $size, int $ver, int $mask): void {
    $bit=fn(int $v,int $i): bool => (($v>>$i)&1)===1;
    $d=$mask; $r=$d; for($i=0;$i<10;$i++) $r=($r<<1)^(($r>>9)*0x537); // nivel M = 00
    $f=(($d<<10)|($r&0x3FF))^0x5412;
    for($i=0;$i<=5;$i++) $m[$i][8]=$bit($f,$i);
    $m[7][8]=$bit($f,6); $m[8][8]=$bit($f,7); $m[8][7]=$bit($f,8);
    for($i=9;$i<15;$i++) $m[8][14-$i]=$bit($f,$i);
    for($i=0;$i<8;$i++) $m[8][$size-1-$i]=$bit($f,$i);
    for($i=8;$i<15;$i++) $m[$size-15+$i][8]=$bit($f,$i);
    $m[$size-8][8]=true;
    if($ver>=7){
        $r=$ver; for($i=0;$i<12;$i++) $r=($r<<1)^(($r>>11)*0x1F25);
        $v=($ver<<12)|($r&0xFFF);
        for($i=0;$i<18;$i++){ $b=$bit($v,$i); $a=$size-11+$i%3; $c=intdiv($i,3); $m[$c][$a]=$b; $m[$a][$c]=$b; }
    }
}
function qrPenalty(array $m, int $size): int {
    $s=0;
    for($pass=0;$pass<2;$pass++) for($i=0;$i<$size;$i++){ $run=1;
        for($j=1;$j<=$size;$j++){ $cur=$j<$size?($pass?$m[$j][$i]:$m[$i][$j]):null; $prev=$pass?$m[$j-1][$i]:$m[$i][$j-1];
            if($cur===$prev) $run++; else { if($run>=5) $s+=$run-2; $run=1; } } }
    for($y=0;$y<$size-1;$y++) for($x=0;$x<$size-1;$x++){ $c=$m[$y][$x]; if($c===$m[$y][$x+1]&&$c===$m[$y+1][$x]&&$c===$m[$y+1][$x+1]) $s+=3; }
    $p1=[true,false,true,true,true,false,true,false,false,false,false]; $p2=array_reverse($p1);
    for($pass=0;$pass<2;$pass++) for($i=0;$i<$size;$i++) for($j=0;$j<=$size-11;$j++){
        $ok1=$ok2=true; for($k=0;$k<11;$k++){ $v=$pass?$m[$j+$k][$i]:$m[$i][$j+$k]; if($v!==$p1[$k]) $ok1=false; if($v!==$p2[$k]) $ok2=false; }
        if($ok1) $s+=40; if($ok2) $s+=40; }
    $dark=0; foreach($m as $row) foreach($row as $v) if($v) $dark++;
    $t=$size*$size; $s+=max(0,(int)ceil(abs($dark*20-$t*10)/$t)-1)*10;
    return $s;
}
/** SVG del código QR (se inserta en la página; no requiere img-src externo). */
function qrSvg(string $data, int $px=200): string {
    $m=qrMatrix($data); $n=count($m); $q=4; $d='';
    foreach($m as $y=>$row) foreach($row as $x=>$v) if($v) $d.='M'.($x+$q).' '.($y+$q).'h1v1h-1z';
    $t=$n+2*$q;
    return '<svg class="qr" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$t.' '.$t.'" width="'.$px.'" height="'.$px.'" shape-rendering="crispEdges" role="img" aria-label="Código QR"><rect width="'.$t.'" height="'.$t.'" fill="#fff"/><path fill="#000" d="'.$d.'"/></svg>';
}
