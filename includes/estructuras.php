<?php
// Aqui tenemos clases genericas, pilas, colas, ordenamiento burbuja y busqueda secuencial

// Clase generica base: sirve para guardar listas donde todos los datos sean del mismo tipo
abstract class ColeccionGenerica
{
    protected $elementos = [];
    protected $tipo;

    // Al crear la lista le decimos que tipo de dato va a aceptar
    public function __construct($tipo = 'mixed')
    {
        $this->tipo = $tipo;
    }

    public function getTipo()
    {
        return $this->tipo;
    }

    // Revisa que cada dato nuevo si sea del tipo que pedimos al principio
    protected function validar($valor)
    {
        switch ($this->tipo) {
            case 'mixed':  return;
            case 'int':    $ok = is_int($valor); break;
            case 'float':  $ok = is_float($valor) || is_int($valor); break;
            case 'string': $ok = is_string($valor); break;
            case 'bool':   $ok = is_bool($valor); break;
            case 'array':  $ok = is_array($valor); break;
            case 'object': $ok = is_object($valor); break;
            default:       $ok = ($valor instanceof $this->tipo); break;
        }
        if (!$ok) {
            throw new InvalidArgumentException(
                "Esta " . static::class . "<" . $this->tipo . "> solo acepta elementos de tipo '" . $this->tipo . "'."
            );
        }
    }

    // Devuelve cuantos elementos hay guardados
    public function tamano()
    {
        return count($this->elementos);
    }

    // Revisa si la lista esta vacia
    public function estaVacia()
    {
        return count($this->elementos) === 0;
    }

    // Borra todos los elementos
    public function vaciar()
    {
        $this->elementos = [];
    }
}

// Pilas para registrar y deshacer goles
// El ultimo gol que entra es el primero que se quita al deshacer
class Pila extends ColeccionGenerica
{
    // Mete un elemento hasta arriba de la pila
    public function push($valor)
    {
        $this->validar($valor);
        $this->elementos[] = $valor;
        return $this;
    }

    // Saca y devuelve el ultimo elemento que entro
    public function pop()
    {
        if ($this->estaVacia()) {
            return null;
        }
        return array_pop($this->elementos);
    }

    // Muestra cual es el elemento de hasta arriba sin sacarlo
    public function peek()
    {
        if ($this->estaVacia()) {
            return null;
        }
        return $this->elementos[count($this->elementos) - 1];
    }

    // Convierte la pila a un arreglo normal empezando por el ultimo que entro
    public function aArray()
    {
        return array_reverse($this->elementos);
    }
}

// Colas de arbitros
// El primer arbitro que llega es el primero al que le toca pitar
class Cola extends ColeccionGenerica
{
    // Forma un arbitro al final de la fila
    public function encolar($valor)
    {
        $this->validar($valor);
        $this->elementos[] = $valor;
        return $this;
    }

    // Saca al arbitro que va hasta adelante de la fila para asignarle partido
    public function desencolar()
    {
        if ($this->estaVacia()) {
            return null;
        }
        return array_shift($this->elementos);
    }

    // Mira quien sigue en la fila sin sacarlo todavia
    public function frente()
    {
        if ($this->estaVacia()) {
            return null;
        }
        return $this->elementos[0];
    }

    // Devuelve toda la fila en orden de llegada
    public function aArray()
    {
        return $this->elementos;
    }
}

// Ordenamiento burbuja
// Sirve para acomodar las tablas comparando de dos en dos
class Ordenamiento
{
    // Va recorriendo la lista y cambiando de lugar los elementos si estan en desorden
    public static function burbuja(array $datos, callable $cmp)
    {
        $a = array_values($datos);
        $n = count($a);
        for ($i = 0; $i < $n - 1; $i++) {
            $huboCambio = false;
            for ($j = 0; $j < $n - $i - 1; $j++) {
                if ($cmp($a[$j], $a[$j + 1]) > 0) {
                    $tmp        = $a[$j];
                    $a[$j]      = $a[$j + 1];
                    $a[$j + 1]  = $tmp;
                    $huboCambio = true;
                }
            }
            // Si en toda una vuelta no hubo cambios, ya quedo ordenado y terminamos antes
            if (!$huboCambio) {
                break;
            }
        }
        return $a;
    }
}

// Busqueda secuencial explicita
// Busca un dato revisando uno por uno desde el inicio hasta encontrarlo con un ciclo for
class Busqueda
{
    // Busca el primer elemento que cumpla lo que pedimos y regresa su posicion (o -1 si no esta)
    public static function secuencial(array $datos, callable $criterio)
    {
        $datos = array_values($datos);
        $n = count($datos);
        for ($i = 0; $i < $n; $i++) {
            if ($criterio($datos[$i])) {
                return $i;
            }
        }
        return -1;
    }

    // Igual revisa uno por uno, pero junta y regresa todos los que coincidan
    public static function secuencialTodos(array $datos, callable $criterio)
    {
        $datos = array_values($datos);
        $n = count($datos);
        $res = [];
        for ($i = 0; $i < $n; $i++) {
            if ($criterio($datos[$i])) {
                $res[] = $datos[$i];
            }
        }
        return $res;
    }
}