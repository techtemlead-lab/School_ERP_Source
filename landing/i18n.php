<?php
require_once __DIR__.'/../config/runtime.php';
function landing_language(): string {
 static $language=null;if($language!==null)return $language;
 $language=in_array($_GET['lang']??'', ['en','bn'],true)?$_GET['lang']:(in_array($_COOKIE['erp_public_lang']??'',['en','bn'],true)?$_COOKIE['erp_public_lang']:'en');
 if(isset($_GET['lang'])&&in_array($_GET['lang'],['en','bn'],true))setcookie('erp_public_lang',$language,['expires'=>time()+31536000,'path'=>'/','secure'=>erp_https(),'httponly'=>true,'samesite'=>'Lax']);
 return $language;
}
function landing_dictionary(): array {static $dictionary=null;return $dictionary??=json_decode(file_get_contents(__DIR__.'/bn.json'),true,512,JSON_THROW_ON_ERROR);}
function landing_t(string $text): string {
 if(landing_language()!=='bn')return $text;
 $dictionary=landing_dictionary();if(isset($dictionary[$text]))return $dictionary[$text];
 if(preg_match('/^([\s✓]*)(.*?)([\s→↗\x{001a}]*)$/u',$text,$parts)&&isset($dictionary[$parts[2]]))return $parts[1].$dictionary[$parts[2]].$parts[3];
 return $text;
}
function landing_language_url(string $language): string {$params=$_GET;$params['lang']=$language;return (parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/').'?'.http_build_query($params);}
function landing_translate_html(string $html): string {
 if(landing_language()!=='bn')return $html;
 $pieces=preg_split('~(<script\b[^>]*>.*?</script>|<style\b[^>]*>.*?</style>)~is',$html,-1,PREG_SPLIT_DELIM_CAPTURE);
 foreach($pieces as $index=>&$piece){if($index%2)continue;
  $piece=preg_replace_callback('~>([^<>]+)<~u',function($match){$text=trim(html_entity_decode($match[1],ENT_QUOTES|ENT_HTML5,'UTF-8'));$translated=landing_t($text);if($translated===$text)return $match[0];preg_match('/^\s*/u',$match[1],$before);preg_match('/\s*$/u',$match[1],$after);return '>'.$before[0].erp_e($translated).$after[0].'<';},$piece);
 }unset($piece);return implode('',$pieces);
}
