import { Card, CardBody } from '@wordpress/components';

export default function StatCard({ label, value, color }) {
    return (
        <Card style={{ flex: 1, minWidth: 140 }}>
            <CardBody>
                <div style={{ fontSize: '1.8em', fontWeight: 600, color: color || '#646970' }}>
                    {value}
                </div>
                <div style={{
                    color: '#646970',
                    textTransform: 'uppercase',
                    fontSize: '0.8em',
                    letterSpacing: '0.05em',
                    marginTop: '0.3em'
                }}>
                    {label}
                </div>
            </CardBody>
        </Card>
    );
}
