<?php
namespace App\Http\Middleware;
use Closure; use Illuminate\Http\Request; use Symfony\Component\HttpFoundation\Response;
class VerifyAriyaIntegrationSignature {
 public function handle(Request $request, Closure $next): Response {
  if (!config('ariya_site.enabled')) return response()->json(['ok'=>false,'status'=>'disabled'], 503);
  $ips=config('ariya_site.allowed_ips',[]); if($ips && !in_array($request->ip(),$ips,true)) return response()->json(['ok'=>false,'status'=>'unauthorized'],401);
  $eventId=(string)$request->header('X-Ariya-Event-Id',''); $ts=(string)$request->header('X-Ariya-Timestamp',''); $sig=(string)$request->header('X-Ariya-Signature',''); $secret=(string)config('ariya_site.shared_secret','');
  if($eventId===''||$ts===''||$sig===''||$secret==='') return response()->json(['ok'=>false,'status'=>'unauthorized'],401);
  $time=strtotime($ts); if(!$time || abs(time()-$time)>300) return response()->json(['ok'=>false,'status'=>'unauthorized'],401);
  $expected=hash_hmac('sha256',$ts.'.'.$request->getContent(),$secret); if(!hash_equals($expected,$sig)) return response()->json(['ok'=>false,'status'=>'unauthorized'],401);
  return $next($request);
 }
}
