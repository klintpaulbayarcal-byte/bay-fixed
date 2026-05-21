const fs = require('fs')
const path = require('path')

module.exports = (req, res) => {
  try {
    const filePath = path.join(__dirname, '..', 'frontend', 'dist', 'index.html')
    if (!fs.existsSync(filePath)) {
      res.statusCode = 404
      res.setHeader('Content-Type', 'text/plain')
      res.end('index.html not found')
      return
    }
    const content = fs.readFileSync(filePath, 'utf8')
    res.setHeader('Content-Type', 'text/html')
    res.end(content)
  } catch (err) {
    res.statusCode = 500
    res.setHeader('Content-Type', 'text/plain')
    res.end('server error')
  }
}
