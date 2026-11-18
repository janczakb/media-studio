import {Button,Slider,Tabs,ToggleButton,ToggleButtonGroup,Tooltip} from '@heroui/react';
import {AspectRatioIcon,SquareIcon,ZoomInIcon,ZoomOutIcon} from '@hugeicons/core-free-icons';
import {type ReactElement} from 'react';
import {Icon} from '../../ui';
import {clampPhotoZoom,PHOTO_ZOOM_MAX,PHOTO_ZOOM_MIN} from './photo-zoom';

export type PhotoMedia = 'all'|'image'|'video'|'starred';
export type PhotoPeriod = 'years'|'months'|'days';
export type PhotoAspect = 'square'|'original';
export type PhotosLabels = {
  photos:string;photoAll:string;photosOnly:string;videosOnly:string;photoStarred:string;
  photoYears:string;photoMonths:string;photoDays:string;photoPeriod:string;
  photoAspect:string;photoSquare:string;photoZoom:string;zoomIn:string;zoomOut:string;
};

export type PhotosToolbarProps = {
  media:PhotoMedia;onMediaChange:(media:PhotoMedia)=>void;
  period:PhotoPeriod;onPeriodChange:(period:PhotoPeriod)=>void;
  aspect:PhotoAspect;onAspectChange:(aspect:PhotoAspect)=>void;
  zoom:number;onZoomChange:(zoom:number)=>void;
  portal:HTMLElement;t:PhotosLabels;
};

export function PhotosToolbar({media,onMediaChange,period,onPeriodChange,aspect,onAspectChange,zoom,onZoomChange,portal,t}:PhotosToolbarProps) {
  const mediaTabs:readonly [PhotoMedia,string][]=[['all',t.photoAll],['image',t.photosOnly],['video',t.videosOnly],['starred',t.photoStarred]];
  const periodTabs:readonly [PhotoPeriod,string][]=[['years',t.photoYears],['months',t.photoMonths],['days',t.photoDays]];
  const tip=(label:string,control:ReactElement)=><Tooltip delay={300} closeDelay={0}>{control}<Tooltip.Content UNSTABLE_portalContainer={portal}>{label}</Tooltip.Content></Tooltip>;
  return <div className="fms-photos-toolbar">
    <Tabs variant="primary" selectedKey={media} onSelectionChange={key=>{const next=mediaTabs.find(([id])=>id===key);if(next)onMediaChange(next[0]);}} className="fms-photos-media-tabs">
      <Tabs.ListContainer><Tabs.List aria-label={t.photos}>{mediaTabs.map(([id,label])=><Tabs.Tab id={id} key={id}>{label}<Tabs.Indicator/></Tabs.Tab>)}</Tabs.List></Tabs.ListContainer>
    </Tabs>
    <Tabs variant="primary" selectedKey={period} onSelectionChange={key=>{const next=periodTabs.find(([id])=>id===key);if(next)onPeriodChange(next[0]);}} className="fms-photos-period-tabs">
      <Tabs.ListContainer><Tabs.List aria-label={t.photoPeriod}>{periodTabs.map(([id,label])=><Tabs.Tab id={id} key={id}>{label}<Tabs.Indicator/></Tabs.Tab>)}</Tabs.List></Tabs.ListContainer>
    </Tabs>
    <div className="fms-photos-display-controls">
      <ToggleButtonGroup size="sm" selectionMode="single" disallowEmptySelection selectedKeys={[aspect]} onSelectionChange={keys=>{
        if(keys.has('square'))onAspectChange('square');else if(keys.has('original'))onAspectChange('original');
      }} aria-label={t.photoAspect} className="fms-photos-aspect-controls">
        {tip(t.photoSquare,<ToggleButton id="square" isIconOnly aria-label={t.photoSquare}><Icon icon={SquareIcon} size={18}/></ToggleButton>)}
        {tip(t.photoAspect,<ToggleButton id="original" isIconOnly aria-label={t.photoAspect}><Icon icon={AspectRatioIcon} size={18}/></ToggleButton>)}
      </ToggleButtonGroup>
      <div className="fms-photos-zoom-controls">
        {tip(t.zoomOut,<Button size="sm" variant="ghost" isIconOnly aria-label={t.zoomOut} isDisabled={zoom<=PHOTO_ZOOM_MIN} onPress={()=>onZoomChange(clampPhotoZoom(zoom/1.2))}><Icon icon={ZoomOutIcon} size={18}/></Button>)}
        <Slider className="fms-photos-zoom-slider" aria-label={t.photoZoom} minValue={PHOTO_ZOOM_MIN} maxValue={PHOTO_ZOOM_MAX} step={1} value={Math.round(zoom)} onChange={value=>onZoomChange(clampPhotoZoom(Array.isArray(value)?value[0]??PHOTO_ZOOM_MIN:value))}>
          <Slider.Track><Slider.Fill/><Slider.Thumb/></Slider.Track>
        </Slider>
        {tip(t.zoomIn,<Button size="sm" variant="ghost" isIconOnly aria-label={t.zoomIn} isDisabled={zoom>=PHOTO_ZOOM_MAX} onPress={()=>onZoomChange(clampPhotoZoom(zoom*1.2))}><Icon icon={ZoomInIcon} size={18}/></Button>)}
      </div>
    </div>
  </div>;
}
