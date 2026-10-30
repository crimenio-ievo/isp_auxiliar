const {PNG}=require('pngjs'); const fs=require('fs'); const {execFileSync}=require('child_process');
const lat=-21.0025,lng=-42.8375,z=17,n=2**z; const x=Math.floor((lng+180)/360*n); const y=Math.floor((1-Math.asinh(Math.tan(lat*Math.PI/180))/Math.PI)/2*n);
const out=new PNG({width:512,height:512});
for(const dx of [0,1]) for(const dy of [0,1]){
  const buf=execFileSync('curl',['-s','-m','15','-e','https://testes.ievo.com.br/mapa','-A','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120 Safari/537.36',`https://tile.openstreetmap.org/${z}/${x+dx}/${y+dy}.png`]);
  const t=PNG.sync.read(buf); PNG.bitblt(t,out,0,0,256,256,dx*256,dy*256);
}
fs.writeFileSync('../osm_light.png',PNG.sync.write(out));
const cl=v=>Math.max(0,Math.min(255,v)); const d=new PNG({width:512,height:512});
for(let i=0;i<out.data.length;i+=4){
  let r=255-out.data[i],g=255-out.data[i+1],b=255-out.data[i+2];
  [r,g,b]=[-0.574*r+1.43*g+0.144*b, 0.426*r+0.43*g+0.144*b, 0.426*r+1.43*g-0.856*b];   // hue-rotate(180deg)
  [r,g,b]=[r,g,b].map(c=>((c*0.9)-128)*0.9+128);                                          // brightness(.9) contrast(.9)
  d.data[i]=cl(r);d.data[i+1]=cl(g);d.data[i+2]=cl(b);d.data[i+3]=255;
}
fs.writeFileSync('../osm_dark.png',PNG.sync.write(d)); console.log('ok');
