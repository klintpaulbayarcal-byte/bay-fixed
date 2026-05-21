const mysql = require('mysql2/promise');

function setCors(res, req) {
    const origin = req.headers.origin || process.env.ALLOWED_ORIGIN || '*';
    res.setHeader('Access-Control-Allow-Origin', origin);
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    res.setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
    res.setHeader('Access-Control-Allow-Credentials', 'true');
}

module.exports = async function (req, res) {
    setCors(res, req);
    if (req.method === 'OPTIONS') {
        res.status(204).end();
        return;
    }
    if (req.method !== 'GET') {
        res.status(405).json({ success: false, message: 'Method not allowed' });
        return;
    }

    try {
        const host = process.env.DB_HOST || 'localhost';
        const user = process.env.DB_USER || 'if0_41979375';
        const password = process.env.DB_PASSWORD || 'bebepogi2004';
        const database = process.env.DB_NAME || 'if0_41979375_websystem';
        const port = Number(process.env.DB_PORT || 3306);

        const conn = await mysql.createConnection({ host, user, password, database, port });

        const [stockRows] = await conn.query("SHOW COLUMNS FROM products LIKE 'stock_quantity'");
        if (stockRows.length === 0) {
            await conn.query("ALTER TABLE products ADD COLUMN stock_quantity INT NOT NULL DEFAULT 20 AFTER price");
        }

        const [catRows] = await conn.query("SHOW COLUMNS FROM products LIKE 'category'");
        if (catRows.length === 0) {
            await conn.query("ALTER TABLE products ADD COLUMN category VARCHAR(30) NOT NULL DEFAULT 'coffee' AFTER name");
        }

        const [rows] = await conn.query("SELECT id, name, category, description, price, image_url, stock_quantity FROM products WHERE is_available = 1 ORDER BY category ASC, name ASC");
        await conn.end();

        res.json({ success: true, products: rows, count: rows.length });
    } catch (err) {
        console.error(err);
        res.status(500).json({ success: false, message: err && err.message ? err.message : String(err) });
    }
};
