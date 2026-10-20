import { useRef } from 'react';
import type { PointerEvent } from 'react';

export type OrbState = 'idle' | 'listening' | 'thinking' | 'speaking';

const CSS = `
.luzma-orb{position:relative;display:grid;place-items:center;perspective:900px;--rx:0deg;--ry:0deg}
.luzma-orb .stage{position:relative;width:100%;height:100%;transform-style:preserve-3d;transform:rotateX(var(--rx)) rotateY(var(--ry));transition:transform .25s ease-out}
.luzma-orb .glow{position:absolute;inset:6%;border-radius:50%;background:radial-gradient(circle,rgba(255,90,110,.55),rgba(190,30,60,.25) 45%,transparent 70%);filter:blur(22px);animation:orb-glow 5s ease-in-out infinite}
.luzma-orb .core{position:absolute;inset:19%;border-radius:50%;overflow:hidden;background:radial-gradient(circle at 34% 28%,rgba(255,215,205,.45),rgba(190,45,65,.3) 22%,rgba(88,10,28,.95) 60%,rgba(18,2,8,1) 100%);box-shadow:inset 0 0 36px rgba(255,150,120,.3),inset -20px -26px 56px rgba(10,0,4,.85),0 0 64px rgba(200,40,62,.45);animation:orb-float 7s ease-in-out infinite}
.luzma-orb .swirl{position:absolute;inset:-35%;background:conic-gradient(from 0deg,rgba(255,80,105,0),rgba(255,80,105,.7),rgba(232,201,138,.35),rgba(255,80,105,0),rgba(255,120,135,.6),rgba(255,80,105,0));filter:blur(12px);opacity:.9;animation:orb-spin 11s linear infinite}
.luzma-orb .swirl.b{background:conic-gradient(from 90deg,rgba(214,54,79,0),rgba(214,54,79,.6),rgba(255,217,160,.4),rgba(214,54,79,0));animation:orb-spin 17s linear infinite reverse;opacity:.8}
.luzma-orb .sparks{position:absolute;inset:0;background-image:radial-gradient(1.5px 1.5px at 22% 30%,#fff,transparent),radial-gradient(1px 1px at 68% 22%,#ffe2b8,transparent),radial-gradient(1.5px 1.5px at 40% 70%,#fff,transparent),radial-gradient(1px 1px at 78% 62%,#ffd0d6,transparent),radial-gradient(1px 1px at 55% 45%,#fff,transparent),radial-gradient(1.2px 1.2px at 30% 55%,#ffe2b8,transparent);animation:orb-twinkle 3.2s ease-in-out infinite}
.luzma-orb .shine{position:absolute;inset:0;border-radius:50%;background:radial-gradient(ellipse at 30% 22%,rgba(255,255,255,.4),transparent 34%),linear-gradient(160deg,rgba(255,255,255,.1),transparent 40%)}
.luzma-orb .ring{position:absolute;inset:5%;border-radius:50%;border:1.5px solid rgba(232,196,140,.7);box-shadow:0 0 14px rgba(232,196,140,.35),inset 0 0 12px rgba(232,196,140,.2);animation:orb-ring-a 16s linear infinite}
.luzma-orb .ring.two{inset:2%;border-color:rgba(255,150,160,.55);animation:orb-ring-b 22s linear infinite}
.luzma-orb .ring i{position:absolute;top:-5px;left:50%;width:9px;height:9px;margin-left:-4.5px;border-radius:50%;background:radial-gradient(circle at 35% 30%,#fff,#e8c98a 55%,#b7893f);box-shadow:0 0 14px #f0c987}
.luzma-orb[data-state=thinking] .swirl{animation-duration:2.6s}
.luzma-orb[data-state=thinking] .swirl.b{animation-duration:4s}
.luzma-orb[data-state=thinking] .ring{animation-duration:5s}
.luzma-orb[data-state=thinking] .ring.two{animation-duration:7s}
.luzma-orb[data-state=listening] .core{animation:orb-listen 1.4s ease-in-out infinite}
.luzma-orb[data-state=listening] .glow{animation:orb-glow 1.4s ease-in-out infinite;filter:blur(18px)}
.luzma-orb[data-state=listening] .swirl{animation-duration:6s}
.luzma-orb[data-state=speaking] .core{animation:orb-speak .9s ease-in-out infinite}
.luzma-orb[data-state=speaking] .glow{animation:orb-glow 1s ease-in-out infinite}
.luzma-orb[data-state=speaking] .ring{animation-duration:9s}
@keyframes orb-spin{to{transform:rotate(360deg)}}
@keyframes orb-float{0%,100%{transform:translateY(-1.5%)}50%{transform:translateY(1.5%)}}
@keyframes orb-glow{0%,100%{opacity:.75;transform:scale(1)}50%{opacity:1;transform:scale(1.08)}}
@keyframes orb-twinkle{0%,100%{opacity:.5}50%{opacity:1}}
@keyframes orb-listen{0%,100%{transform:scale(1)}50%{transform:scale(1.07)}}
@keyframes orb-speak{0%,100%{transform:scale(.99)}30%{transform:scale(1.05)}60%{transform:scale(1.01)}}
@keyframes orb-ring-a{from{transform:rotateX(72deg) rotateY(-18deg) rotateZ(0deg)}to{transform:rotateX(72deg) rotateY(-18deg) rotateZ(360deg)}}
@keyframes orb-ring-b{from{transform:rotateX(66deg) rotateY(26deg) rotateZ(360deg)}to{transform:rotateX(66deg) rotateY(26deg) rotateZ(0deg)}}
@media (prefers-reduced-motion: reduce){.luzma-orb *{animation:none!important}}
`;

/** Luzma's sphere: pure CSS 3D with orbiting rings. It reacts to what she is doing and follows the pointer slightly. */
export function LuzmaOrb({
    state = 'idle',
    size = 260,
}: {
    state?: OrbState;
    size?: number | string;
}) {
    const scene = useRef<HTMLDivElement>(null);

    const move = (event: PointerEvent<HTMLDivElement>) => {
        const box = scene.current?.getBoundingClientRect();

        if (!box) {
            return;
        }

        const x = (event.clientX - (box.left + box.width / 2)) / box.width;
        const y = (event.clientY - (box.top + box.height / 2)) / box.height;
        scene.current?.style.setProperty(
            '--ry',
            `${Math.max(-1, Math.min(1, x)) * 14}deg`,
        );
        scene.current?.style.setProperty(
            '--rx',
            `${Math.max(-1, Math.min(1, y)) * -12}deg`,
        );
    };

    return (
        <div
            ref={scene}
            className="luzma-orb"
            data-state={state}
            style={{ width: size, height: size }}
            onPointerMove={move}
            aria-hidden
        >
            <style>{CSS}</style>
            <div className="stage">
                <div className="glow" />
                <div className="two ring">
                    <i />
                </div>
                <div className="core">
                    <div className="swirl" />
                    <div className="swirl b" />
                    <div className="sparks" />
                    <div className="shine" />
                </div>
                <div className="ring">
                    <i />
                </div>
            </div>
        </div>
    );
}
