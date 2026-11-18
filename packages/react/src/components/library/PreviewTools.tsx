import {PreviewDockOverride} from './PersistentActionDock';
import {AssistantToggle,useAssistantPreview} from '../../assistant/AssistantBoundary';
import {Button, Tooltip} from '@heroui/react';
import {Delete02Icon, Edit03Icon, ViewIcon, ViewOffIcon, MoreHorizontalIcon, ScissorIcon, Download04Icon, Comment01Icon} from '@hugeicons/core-free-icons';
import {AnimatePresence, motion, useReducedMotion, useIsPresent} from 'motion/react';
import {useCallback, useRef, useState, type ReactNode} from 'react';
import type {Entry, PreviewAction, StudioOptions} from '../../contracts';
import {Icon, type Messages} from '../../ui';
import {VideoTools} from './VideoTools';

function ActionContent({children}: {children: ReactNode}) {
  const present = useIsPresent();
  return <div className="fms-preview-action-content" inert={!present}>{children}</div>;
}

const defaultActions: PreviewAction[] = [{id:'download'}, {id: 'trim'}, {id: 'draw'}, {id: 'annotations'}, {id: 'trash'}, {id:'comments',separatorBefore:true}];

interface Props {
  entry: Entry;
  workspace: string;
  options: StudioOptions;
  portal: HTMLDivElement;
  t: Messages;
  editing: boolean;
  closing?:boolean;
  canTrash: boolean;
  showTrash: boolean;
  canDownload: boolean;
  canComments: boolean;
  commentsActive: boolean;
  onDownload:()=>void;
  onComments:()=>void;
  canDraw: boolean;
  drawingReady: boolean;
  annotationsVisible: boolean;
  hasAnnotations: boolean;
  onDraw: () => void;
  onToggleAnnotations: () => void;
  onTrash: (entry: Entry) => void;
  onChanged: (entry: Entry) => void;
  onEditing: (editing: boolean) => void;
}

export function PreviewTools({entry, workspace, options, portal, t, editing, closing=false, canTrash, showTrash, canDownload, canComments, commentsActive, onDownload, onComments, canDraw, drawingReady, annotationsVisible, hasAnnotations, onDraw, onToggleAnnotations, onTrash, onChanged, onEditing}: Props) {
  const assistant=useAssistantPreview();
  const showAgent=assistant.enabled&&!editing;
  const owner = JSON.stringify([workspace, entry.id, entry.versionId]);
  const [video, setVideo] = useState<{owner: string; start: (() => void) | null; busy: boolean}>();
  const onTrimReady = useCallback((start: (() => void) | null, busy: boolean) => {
    setVideo({owner, start, busy});
  }, [owner]);
  const currentVideo = video?.owner === owner ? video : undefined;
  // Keep the last resolved image action set while the next image is loading.
  // Pending controls stay disabled so they cannot act on the previous image.
  const resolvedAnnotations = useRef(false);
  if (drawingReady) resolvedAnnotations.current = hasAnnotations;
  const showAnnotations = drawingReady ? hasAnnotations : resolvedAnnotations.current;
  const enabled = options.previewActions !== false && !entry.trashedAt;
  const configured = options.previewActions || defaultActions;
  const available = configured.some(action => action.id === 'trash') ? configured : [...configured, {id:'trash'}];
  const actions = [...available.filter(a=>a.id==='download'),...available.filter(a=>a.id!=='download'&&a.id!=='comments'),...available.filter(a=>a.id==='comments').map(a=>({...a,separatorBefore:true}))];
  const visible = enabled && !editing && !currentVideo?.busy ? actions.filter(action => {
    if (action.id === 'trash') return showTrash;
    if (action.visible && !action.visible(entry)) return false;
    if (action.id === 'download') return canDownload;
    if (action.id === 'comments') return canComments;
    if (action.id === 'draw') return entry.mediaType === 'image' && canDraw;
    if (action.id === 'annotations') return entry.mediaType === 'image' && showAnnotations;
    if (action.id === 'trim') return entry.mediaType === 'video' && !!currentVideo?.start;
    return !!action.onPress;
  }) : [];
  const count=visible.length+(showAgent?1:0);
  const barWidth = 12 + count * 40 + Math.max(0, count - 1) * 6 + visible.filter((action, index) => index > 0 && action.separatorBefore).length * 7;
  const reducedMotion = useReducedMotion();
  const transition = {duration: reducedMotion ? 0 : .18, ease: 'easeOut' as const};

  return <>
    {enabled && entry.mediaType === 'video' && <VideoTools key={owner} entry={entry} workspace={workspace}
      options={options} t={t} onChanged={onChanged} onEditing={onEditing} onTrimReady={onTrimReady}/>}
    <PreviewDockOverride width={barWidth} visible={enabled&&count>0} closing={closing}>
          <ActionContent>
            {visible.map((action, index) => {
              const builtIns: Record<string, {label: string; icon: NonNullable<PreviewAction['icon']>}> = {
                download:{label:t.download,icon:Download04Icon},
                comments:{label:t.comments,icon:Comment01Icon},
                trim: {label: t.trimVideo, icon: ScissorIcon},
                trash: {label: t.moveToTrash, icon: Delete02Icon},
                draw: {label: t.drawOnImage, icon: Edit03Icon},
                annotations: {label: annotationsVisible ? t.hideAnnotations : t.showAnnotations, icon: annotationsVisible ? ViewOffIcon : ViewIcon},
              };
              const label = action.label ?? builtIns[action.id]?.label ?? action.id;
              return <div key={index} className="fms-preview-action-slot">
                {index > 0 && action.separatorBefore && <span role="separator" aria-orientation="vertical" className="fms-preview-action-separator"/>}
                <AnimatePresence mode="wait" initial={false}>
                <motion.div key={action.id} className="fms-preview-action"
                  initial={{opacity:0,y:4,filter:'blur(2px)'}} animate={{opacity:1,y:0,filter:'blur(0px)'}}
                  exit={{opacity:0,y:-4,filter:'blur(2px)'}} transition={transition}>
                <Tooltip delay={300}>
                  <Button isIconOnly variant="ghost" aria-label={label} {...(action.id==='comments'?{'aria-pressed':commentsActive}:{})} className={(action.id === 'draw' || action.id === 'annotations') && !drawingReady ? 'fms-preview-action-pending' : ''} isDisabled={(action.id === 'trash' && !canTrash) || ((action.id === 'draw' || action.id === 'annotations') && !drawingReady)} onPress={() => {
                    if (action.id === 'download') onDownload();
                    else if (action.id === 'comments') onComments();
                    else if (action.id === 'trim') currentVideo?.start?.();
                    else if (action.id === 'trash') onTrash(entry);
                    else if (action.id === 'draw') onDraw();
                    else if (action.id === 'annotations') onToggleAnnotations();
                    else action.onPress?.({entry, workspace});
                  }}>
                    <Icon icon={action.icon ?? builtIns[action.id]?.icon ?? MoreHorizontalIcon} size={24} strokeWidth={action.id === 'draw' ? 2.25 : 2}/>
                  </Button>
                  <Tooltip.Content UNSTABLE_portalContainer={portal}>{label}</Tooltip.Content>
                </Tooltip>
              </motion.div>
              </AnimatePresence>
              </div>;
            })}
            {showAgent&&<AssistantToggle locale={options.locale??'en'}/>}
          </ActionContent>
    </PreviewDockOverride>
  </>;
}
