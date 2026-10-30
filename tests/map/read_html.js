// jsdom não carrega recursos externos por padrão. Usa o asset local na mesma
// ordem do HTML, sem permitir rede, tiles ou dependência de uma sessão HTTP.
const fs = require('fs');
const path = require('path');
module.exports = function readMapHtml(file = path.join(__dirname,'../../modules/isp_map/isp-network-map.html')){
  return fs.readFileSync(file,'utf8').replace('<script src="/mapa/diagram.js"></script>',()=>{
    const script = fs.readFileSync(path.join(__dirname,'../../modules/isp_map/diagram.js'),'utf8');
    return '<script>'+script.replace(/<\/script/gi,'<\\/script')+'</script>';
  });
};
