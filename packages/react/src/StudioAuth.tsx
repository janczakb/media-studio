import {applyPrimaryColor} from './primary-color';
import {enableStudioInteractions} from './interaction-support';
import {createRoot} from 'react-dom/client';
import {Button,Input,InputGroup,Label,TextField} from '@heroui/react';
import {useState,type FormEvent} from 'react';
import {Icon} from './ui';
import {ViewIcon,ViewOffIcon} from '@hugeicons/core-free-icons';
export interface StudioAuthOptions{screen:string;token?:string;email?:string;prefix:string;home:string;registration:boolean;primaryColor:string;stylesheetUrl:string;}
function AuthForm({options:o}:{options:StudioAuthOptions}){
 const [busy,setBusy]=useState(false),[error,setError]=useState(''),[status,setStatus]=useState(''),[visible,setVisible]=useState(false),[qr,setQr]=useState(''),[codes,setCodes]=useState<string[]>([]),[recovery,setRecovery]=useState(false);
 const titles:Record<string,string>={login:'Welcome back',register:'Create your account',forgot:'Reset your password',reset:'Choose a new password',verify:'Verify your email',confirm:'Confirm your password','two-factor':'Two-factor authentication',security:'Account security'};
 async function request(path:string,body:Record<string,unknown>={},method='POST'){
  const response=await fetch(o.prefix+path,{method,credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector<HTMLMetaElement>('meta[name=csrf-token]')?.content??''},...(method==='GET'?{}:{body:JSON.stringify(body)})});
  const result=await response.json().catch(()=>({}));if(!response.ok)throw new Error(Object.values(result.errors??{}).flat().join(' ')||result.message||'This request could not be completed.');return result;
 }
 async function submit(e:FormEvent<HTMLFormElement>){e.preventDefault();if(busy)return;setBusy(true);setError('');const data=Object.fromEntries(new FormData(e.currentTarget));try{
  if(o.screen==='security'){
   if(!qr){await request('/user/two-factor-authentication');const value=await request('/user/two-factor-qr-code',{},'GET');setQr(value.svg);}
   else{await request('/user/confirmed-two-factor-authentication',{code:data.code});setCodes(await request('/user/two-factor-recovery-codes',{},'GET'));setStatus('Two-factor authentication is enabled. Store your recovery codes in a safe place.');}
  }else if(o.screen==='verify'){await request('/email/verification-notification');setStatus('A verification link has been sent.');}
  else if(o.screen==='forgot'){try{await request('/forgot-password',data);}catch{}setStatus('If that account exists, a password reset link has been sent.');}
  else if(o.screen==='reset'){await request('/reset-password',{...data,token:o.token,email:o.email});location.href=o.prefix+'/login';}
  else if(o.screen==='confirm'){await request('/user/confirm-password',data);location.href=o.prefix+'/security';}
  else{const result=await request(o.screen==='two-factor'?'/two-factor-challenge':o.screen==='register'?'/register':'/login',data);location.href=result.two_factor?o.prefix+'/two-factor-challenge':o.home;}
 }catch(e){setError(e instanceof Error?e.message:String(e));}finally{setBusy(false);}}
 const field=(name:string,label:string,type='text')=><TextField name={name} isRequired><Label>{label}</Label>{type==='password'?<InputGroup fullWidth variant="secondary"><InputGroup.Input type={visible?'text':'password'} autoComplete={o.screen==='login'||o.screen==='confirm'?'current-password':'new-password'} minLength={o.screen==='register'||o.screen==='reset'?12:undefined} maxLength={128}/><InputGroup.Suffix><Button type="button" isIconOnly size="sm" variant="ghost" aria-label={visible?'Hide password':'Show password'} onPress={()=>setVisible(!visible)}><Icon icon={visible?ViewOffIcon:ViewIcon} size={18}/></Button></InputGroup.Suffix></InputGroup>:<Input variant="secondary" type={type} autoComplete={name==='email'?'email':'name'}/>}</TextField>;
 return <main className="fms-auth-page"><form onSubmit={e=>void submit(e)}><h1>{titles[o.screen]??'Media Studio'}</h1>
 {o.screen==='register'&&field('name','Name')}
 {['login','register','forgot'].includes(o.screen)&&field('email','Email','email')}
 {['login','register','reset','confirm'].includes(o.screen)&&field('password','Password','password')}
 {['register','reset'].includes(o.screen)&&field('password_confirmation','Confirm password','password')}
 {o.screen==='two-factor'&&<>{field(recovery?'recovery_code':'code',recovery?'Recovery code':'Authentication code')}<Button type="button" variant="ghost" onPress={()=>setRecovery(!recovery)}>{recovery?'Use authentication code':'Use recovery code'}</Button></>}
 {o.screen==='verify'&&<p>Open the verification link in your email before accessing your library.</p>}
 {o.screen==='security'&&<><p>Protect your account with an authenticator app. Setup requires a recently confirmed password.</p>{qr&&<><div className="fms-auth-qr" dangerouslySetInnerHTML={{__html:qr}}/>{field('code','Authentication code')}</>}{codes.length>0&&<ul>{codes.map(code=><li key={code}><code>{code}</code></li>)}</ul>}</>}
 {error&&<p role="alert">{error}</p>}{status&&<p role="status">{status}</p>}
 <Button type="submit" isDisabled={busy} className="fms-auth-submit">{busy?'Please wait…':o.screen==='verify'?'Resend verification email':o.screen==='security'?(qr?'Confirm authentication':'Set up two-factor authentication'):'Continue'}</Button>
 <nav>{o.screen==='login'?<><a href={o.prefix+'/forgot-password'}>Forgot password?</a>{o.registration&&<a href={o.prefix+'/register'}>Create account</a>}</>:<a href={o.screen==='security'?o.home:o.prefix+'/login'}>{o.screen==='security'?'Back to Studio':'Back to sign in'}</a>}</nav>
 </form></main>;
}
export function mountStudioAuth(host:HTMLElement,options:StudioAuthOptions){enableStudioInteractions();const shadow=host.attachShadow({mode:'open'}),link=document.createElement('link');link.rel='stylesheet';link.href=options.stylesheetUrl;const el=document.createElement('div');el.className='fms-root light';applyPrimaryColor(el,options.primaryColor);shadow.append(link,el);const root=createRoot(el);root.render(<AuthForm options={options}/>);return {destroy(){root.unmount();link.remove();el.remove();}};}
