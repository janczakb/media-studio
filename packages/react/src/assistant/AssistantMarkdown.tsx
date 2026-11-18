import Markdown from 'react-markdown';
import {memo} from 'react';
/** Raw HTML and remote images cannot execute or fetch from an assistant response. */
export const AssistantMarkdown=memo(function AssistantMarkdown({text}:{text:string}){
  return <div className="fms-assistant-markdown"><Markdown skipHtml disallowedElements={['img']} components={{a:({children,href})=><a href={href} target="_blank" rel="noopener noreferrer">{children}</a>}}>{text}</Markdown></div>;
});
