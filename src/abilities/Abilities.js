import { useState, useEffect, useMemo } from '@wordpress/element';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import { ToggleControl, Notice, Button, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const DEFAULT_VIEW = {
    type: 'table',
    page: 1,
    perPage: 20,
    search: '',
    sort: { field: 'name', direction: 'asc' },
    fields: [ 'name', 'bundle', 'description', 'tool_name', 'enabled' ],
    layout: {},
};

export default function Abilities() {
    const [ items, setItems ] = useState( null );
    const [ error, setError ] = useState( null );
    const [ view, setView ] = useState( DEFAULT_VIEW );

    useEffect( () => {
        apiFetch( { path: '/mcp-site-manager/v1/abilities' } )
            .then( ( r ) => setItems( r.items ) )
            .catch( ( e ) => setError( e.message || String( e ) ) );
    }, [] );

    const toggle = ( id, next ) => {
        setItems( ( prev ) => prev.map( ( i ) => ( i.id === id ? { ...i, enabled: next } : i ) ) );
        apiFetch( {
            path: `/mcp-site-manager/v1/abilities/${ id }/enabled`,
            method: 'PUT',
            data: { enabled: next },
        } )
            .then( ( r ) => setItems( r.items ) )
            .catch( ( e ) => {
                setError( e.message || String( e ) );
                setItems( ( prev ) => prev.map( ( i ) => ( i.id === id ? { ...i, enabled: !next } : i ) ) );
            } );
    };

    const reset = () => {
        apiFetch( { path: '/mcp-site-manager/v1/abilities/disabled', method: 'DELETE' } )
            .then( ( r ) => setItems( r.items ) )
            .catch( ( e ) => setError( e.message || String( e ) ) );
    };

    const fields = useMemo( () => {
        const bundleOptions = items
            ? Array.from( new Set( items.map( ( i ) => i.bundle ) ) ).sort().map( ( b ) => ( { value: b, label: b } ) )
            : [];
        return [
            {
                id: 'name',
                label: __( 'Name', 'mcp-site-manager' ),
                enableGlobalSearch: true,
                render: ( { item } ) => <code>{ item.name }</code>,
            },
            {
                id: 'bundle',
                label: __( 'Bundle', 'mcp-site-manager' ),
                enableGlobalSearch: true,
                elements: bundleOptions,
            },
            {
                id: 'description',
                label: __( 'Description', 'mcp-site-manager' ),
                enableGlobalSearch: true,
            },
            {
                id: 'tool_name',
                label: __( 'Tool name', 'mcp-site-manager' ),
                render: ( { item } ) => <code>{ item.tool_name }</code>,
            },
            {
                id: 'enabled',
                label: __( 'Enabled', 'mcp-site-manager' ),
                render: ( { item } ) => (
                    <ToggleControl
                        checked={ item.enabled }
                        onChange={ ( next ) => toggle( item.id, next ) }
                        __nextHasNoMarginBottom
                    />
                ),
                elements: [
                    { value: true, label: __( 'Enabled', 'mcp-site-manager' ) },
                    { value: false, label: __( 'Disabled', 'mcp-site-manager' ) },
                ],
            },
        ];
    }, [ items ] );

    const { data, paginationInfo } = useMemo(
        () =>
            items
                ? filterSortAndPaginate( items, view, fields )
                : { data: [], paginationInfo: { totalItems: 0, totalPages: 0 } },
        [ items, view, fields ]
    );

    if ( items === null && !error ) return <Spinner />;
    if ( error && items === null ) {
        return (
            <Notice status="error" isDismissible={ false }>
                { error }
            </Notice>
        );
    }
    if ( !items ) return null;

    const disabledCount = items.filter( ( i ) => !i.enabled ).length;

    return (
        <>
            <div style={ { display: 'flex', gap: '1em', alignItems: 'center', marginBottom: '1em' } }>
                <Button variant="secondary" onClick={ reset } disabled={ disabledCount === 0 }>
                    { __( 'Re-enable all', 'mcp-site-manager' ) }
                </Button>
                <span style={ { color: '#646970' } }>
                    { disabledCount } { __( 'disabled', 'mcp-site-manager' ) } / { items.length } { __( 'total', 'mcp-site-manager' ) }
                </span>
            </div>
            { error && (
                <Notice status="error" onRemove={ () => setError( null ) }>
                    { error }
                </Notice>
            ) }
            <DataViews
                data={ data }
                fields={ fields }
                view={ view }
                onChangeView={ setView }
                paginationInfo={ paginationInfo }
                defaultLayouts={ { table: {} } }
                getItemId={ ( item ) => String( item.id ) }
            />
        </>
    );
}
