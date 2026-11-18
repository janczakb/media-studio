/** Instance-only theme tokens; never interpolate unvalidated CSS. */
export function applyPrimaryColor(root:HTMLElement,value:string){
 const color=/^#[0-9a-f]{6}$/i.test(value)?value:'#0866ff';
 const rgb=[1,3,5].map(i=>parseInt(color.slice(i,i+2),16)/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4);
 const luminance=rgb[0]!*.2126+rgb[1]!*.7152+rgb[2]!*.0722;
 root.style.setProperty('--accent',color);root.style.setProperty('--accent-foreground',luminance>.179?'#000':'#fff');root.style.setProperty('--focus',color);
}
