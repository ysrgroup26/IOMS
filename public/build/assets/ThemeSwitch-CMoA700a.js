import{X as f,u as x,r as y,j as c}from"./app-02eBEYh7.js";import{c as u,a as l}from"./utils-BspYgYGA.js";/**
 * @license lucide-react v0.400.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const w=u("Moon",[["path",{d:"M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z",key:"a7tn18"}]]);/**
 * @license lucide-react v0.400.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const g=u("Sun",[["circle",{cx:"12",cy:"12",r:"4",key:"4exip2"}],["path",{d:"M12 2v2",key:"tus03m"}],["path",{d:"M12 20v2",key:"1lh1kg"}],["path",{d:"m4.93 4.93 1.41 1.41",key:"149t6j"}],["path",{d:"m17.66 17.66 1.41 1.41",key:"ptbguv"}],["path",{d:"M2 12h2",key:"1t8f8n"}],["path",{d:"M20 12h2",key:"1q8mjw"}],["path",{d:"m6.34 17.66-1.41 1.41",key:"1m8zz5"}],["path",{d:"m19.07 4.93-1.41 1.41",key:"1shlcs"}]]),a=[{value:"light",label:"Light theme",Icon:g},{value:"dark",label:"Dark theme",Icon:w}];function M({className:p}){const{darkTheme:d}=f().props,{theme:m,setTheme:h}=x(),i=y.useRef([]);if(!d)return null;const k=(e,o)=>{var t;const n={ArrowRight:1,ArrowDown:1,ArrowLeft:-1,ArrowUp:-1}[e.key];if(!n)return;e.preventDefault();const r=(o+n+a.length)%a.length;h(a[r].value),(t=i.current[r])==null||t.focus()};return c.jsx("div",{role:"radiogroup","aria-label":"Theme",className:l("chrome flex h-9 shrink-0 items-center gap-0.5 rounded-full p-[3px]",p),children:a.map(({value:e,label:o,Icon:n},r)=>{const t=m===e;return c.jsx("button",{ref:s=>{i.current[r]=s},type:"button",role:"radio","aria-checked":t,"aria-label":o,title:o,tabIndex:t?0:-1,onClick:()=>h(e),onKeyDown:s=>k(s,r),className:l("theme-switch-option grid h-[28px] w-[30px] place-items-center rounded-full outline-none","transition-[color,background-color,box-shadow] duration-fast ease-out-soft","focus-visible:ring-4 focus-visible:ring-brand-500/25",t?"theme-switch-selected":"text-slate-500 hover:text-navy-900"),children:c.jsx(n,{className:"h-[15px] w-[15px]",strokeWidth:2,"aria-hidden":"true"})},e)})})}export{w as M,M as T};
