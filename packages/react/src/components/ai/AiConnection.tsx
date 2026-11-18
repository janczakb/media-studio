import {Button, Card, InputGroup, Label, Tabs, TextField, Tooltip} from '@heroui/react';
import {Copy01Icon, Globe02Icon, Tick02Icon} from '@hugeicons/core-free-icons';
import {HugeiconsIcon} from '@hugeicons/react';
import {useState} from 'react';
import type {AiConfiguration} from '../../contracts';
import type en from './locales/en';

type Messages = Record<keyof typeof en, string>;
const clients = [
  {id:'claudeCodeHelp',label:'Claude Code',guide:'https://code.claude.com/docs/en/mcp'},
  {id:'cursorHelp',label:'Cursor',guide:'https://cursor.com/docs/mcp'},
  {id:'vsCodeHelp',label:'VS Code',guide:'https://code.visualstudio.com/docs/agent-customization/mcp-servers'},
  {id:'codexHelp',label:'Codex',guide:'https://developers.openai.com/codex/mcp'},
  {id:'otherHelp',label:'Other',guide:null},
] as const;

export function AiConnection({mcp,t,portal,copied,copy,onTools}: {
  mcp:AiConfiguration['mcp']; t:Messages; portal:HTMLDivElement; copied:string|null;
  copy:(value:string)=>Promise<void>; onTools:()=>void;
}) {
  const [client,setClient]=useState<string>('otherHelp');
  const [example,setExample]=useState('tour');
  const ready=mcp.installed&&mcp.enabled&&mcp.canUse&&!!mcp.endpoint;
  const recipes=[
    {id:'tour',label:t.tour,prompt:t.exampleOne,caption:t.tourCaption},
    {id:'folders',label:t.folders,prompt:t.exampleTwo,caption:t.filesCaption},
    {id:'details',label:t.details,prompt:t.exampleThree,caption:t.detailsCaption},
    {id:'review',label:t.review,prompt:t.exampleFour,caption:t.reviewCaption},
    {id:'compare',label:t.compare,prompt:t.exampleFive,caption:t.compareCaption},
  ];
  return <>
    <div className="fms-ai-intro"><h2>{t.connect}</h2><p>{t.description}</p></div>
    {ready?<>
      <Tabs variant="secondary" selectedKey={client} onSelectionChange={key=>setClient(String(key))}>
        <Tabs.ListContainer><Tabs.List aria-label={t.connectClient}>{clients.map(item=><Tabs.Tab id={item.id} key={item.id}>{item.label}<Tabs.Indicator/></Tabs.Tab>)}</Tabs.List></Tabs.ListContainer>
        {clients.map(item=><Tabs.Panel id={item.id} key={item.id} className="fms-ai-client-panel">
          <div className="fms-ai-steps">
            <Card variant="secondary">
              <Card.Header><Card.Title>1. {t.first}</Card.Title><Card.Description>{t[item.id]}</Card.Description></Card.Header>
              <Card.Content>
                <TextField className="fms-ai-address" value={mcp.endpoint!} name="mcp-server" isReadOnly>
                  <Label>{t.endpoint}</Label>
                  <InputGroup fullWidth>
                    <InputGroup.Prefix><HugeiconsIcon icon={Globe02Icon} size={16} strokeWidth={2}/></InputGroup.Prefix>
                    <InputGroup.Input/>
                    <InputGroup.Suffix className="pe-0"><Tooltip>
                      <Button isIconOnly size="sm" variant="ghost" aria-label={copied===mcp.endpoint?t.copied:t.copy} onPress={()=>void copy(mcp.endpoint!)}><HugeiconsIcon icon={copied===mcp.endpoint?Tick02Icon:Copy01Icon} size={16} strokeWidth={2}/></Button>
                      <Tooltip.Content UNSTABLE_portalContainer={portal}>{copied===mcp.endpoint?t.copied:t.copy}</Tooltip.Content>
                    </Tooltip></InputGroup.Suffix>
                  </InputGroup>
                </TextField>
              </Card.Content>
              {item.guide&&<Card.Footer><a href={item.guide} target="_blank" rel="noopener noreferrer">{t.setupGuide} ↗</a></Card.Footer>}
            </Card>
            <Card variant="secondary">
              <Card.Header><Card.Title>2. {t.second}</Card.Title><Card.Description>{mcp.authentication==='session'?t.session:mcp.authentication==='managed'?t.managedConnection:t.secondText}</Card.Description></Card.Header>
              <Card.Footer><Button size="sm" variant="outline" onPress={onTools}>{t.viewTools}</Button></Card.Footer>
            </Card>
          </div>
        </Tabs.Panel>)}
      </Tabs>
      <div className="fms-ai-examples"><h3>{t.examples}</h3><p>{t.exampleHint}</p>
        <Tabs selectedKey={example} onSelectionChange={key=>setExample(String(key))}>
          <Tabs.ListContainer><Tabs.List aria-label={t.examples}>{recipes.map(item=><Tabs.Tab id={item.id} key={item.id}>{item.label}<Tabs.Indicator/></Tabs.Tab>)}</Tabs.List></Tabs.ListContainer>
          {recipes.map(item=><Tabs.Panel id={item.id} key={item.id}>
            <Card className="fms-ai-prompt">
              <Card.Content><p>{item.prompt}</p></Card.Content>
              <Card.Footer className="fms-ai-prompt-footer"><Card.Description>{item.caption}</Card.Description><Button size="sm" onPress={()=>void copy(item.prompt)}><HugeiconsIcon icon={copied===item.prompt?Tick02Icon:Copy01Icon} size={16} strokeWidth={2}/>{copied===item.prompt?t.copied:t.copyPrompt}</Button></Card.Footer>
            </Card>
          </Tabs.Panel>)}
        </Tabs>
      </div>
    </>:<p>{!mcp.installed?t.install:!mcp.enabled?t.enable:t.denied}</p>}
  </>;
}
