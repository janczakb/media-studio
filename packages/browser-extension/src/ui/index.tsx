import {createRoot} from 'react-dom/client';import {Component,type ReactNode} from 'react';import {Popup} from './Popup';import {Manager} from './Manager';
class Boundary extends Component<{children:ReactNode},{error:string}>{state={error:''};static getDerivedStateFromError(e:Error){return {error:e.message};}render(){return this.state.error?<main className="fc-alert" role="alert">FLEX Capture · {this.state.error}</main>:this.props.children;}}
createRoot(document.getElementById('app')!).render(<Boundary>{location.pathname.endsWith('popup.html')?<Popup/>:<Manager/>}</Boundary>);
